<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\ReviewSubjectException;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\Jobs\BuildTicketApprovalPreview;
use App\AI6\Runs\Models\TicketApprovalPreview;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Ticketfreigabe – AI6'])]
final class TicketApprovalPage extends Component
{
    public Project $project;

    public TicketReadModel $ticketReadModel;

    #[Locked]
    public string $operationId;

    public string $implementationProfile = '';

    public string $implementationModel = '';

    public string $implementationEffort = '';

    /** @var list<array{id: string, profile: string, model: string, effort: string, prompt_profile: string}> */
    public array $reviewerInputs = [];

    /** @var array<string, int> */
    public array $limitInputs = [];

    public string $attentionUserId = '';

    public string $pushMode = 'manual';

    public string $runType = 'implementation';

    public string $reviewSubjectKind = 'managed_branch';

    public string $reviewSourceRef = 'refs/heads/main';

    public string $reviewBaseOid = '';

    public string $reviewSourceOid = '';

    public string $reviewSourceRunId = '';

    public string $reviewTreeOid = '';

    public string $reviewDiffHash = '';

    public string $completionMode = 'manual';

    #[Locked]
    public ?string $previewId = null;

    public function mount(Project $project, string $readModel): void
    {
        Gate::authorize('approveTicket', $project);
        $this->project = $project;
        $this->ticketReadModel = TicketReadModel::query()->whereKey($readModel)->where('project_id', $project->getKey())->firstOrFail();
        $binding = app(EffectiveProjectConfiguration::class)->for($project);
        $fresh = ! app(TicketReadModelFreshness::class)->for($project, $this->ticketReadModel)['stale'];
        abort_unless(app(TicketReadModelUsePolicy::class)->allowsApproval($this->ticketReadModel, $fresh, $binding->configuration->ticketValidationProfile()), 409);
        $ticket = app(TicketV1Parser::class)->parse($this->ticketReadModel->redacted_content);
        abort_unless(($ticket->frontmatter['status'] ?? null) === 'todo', 409);
        $this->operationId = (string) Str::uuid();
        $defaults = $binding->configuration->values['defaults'];
        $profiles = app(AgentProfileRegistry::class);
        $implementation = $profiles->get($defaults['implementation_profile']);
        $this->implementationProfile = $implementation->id;
        $this->implementationModel = $implementation->models[0];
        $this->implementationEffort = $defaults['implementation_effort'];
        $promptProfile = app(PromptCatalog::class)->reviewProfiles()[0]->id;
        foreach ($defaults['reviewers'] as $reviewer) {
            $profile = $profiles->get($reviewer['profile']);
            $this->reviewerInputs[] = [
                'id' => (string) Str::uuid(),
                'profile' => $profile->id,
                'model' => $profile->models[0],
                'effort' => $reviewer['effort'],
                'prompt_profile' => $promptProfile,
            ];
        }
        $this->limitInputs = $binding->configuration->values['limits'];
        $this->pushMode = $binding->configuration->values['push_mode'];
        $this->reviewBaseOid = $this->ticketReadModel->control_commit;
    }

    public function hydrate(): void
    {
        Gate::authorize('approveTicket', $this->project);
        abort_unless($this->ticketReadModel->project_id === $this->project->getKey(), 404);
    }

    public function addReviewer(): void
    {
        $defaults = app(EffectiveProjectConfiguration::class)->for($this->project)->configuration->values['defaults'];
        $default = $defaults['reviewers'][0];
        $profile = app(AgentProfileRegistry::class)->get($default['profile']);
        $effort = $default['effort'];
        foreach ($profile->efforts as $candidate) {
            $duplicate = collect($this->reviewerInputs)->contains(
                fn (array $reviewer): bool => $reviewer['profile'] === $profile->id
                    && $reviewer['model'] === $profile->models[0]
                    && $reviewer['effort'] === $candidate
                    && $reviewer['prompt_profile'] === app(PromptCatalog::class)->reviewProfiles()[0]->id,
            );
            if (! $duplicate) {
                $effort = $candidate;
                break;
            }
        }
        $this->reviewerInputs[] = [
            'id' => (string) Str::uuid(),
            'profile' => $profile->id,
            'model' => $profile->models[0],
            'effort' => $effort,
            'prompt_profile' => app(PromptCatalog::class)->reviewProfiles()[0]->id,
        ];
        $this->invalidatePreview();
    }

    public function removeReviewer(string $slotId): void
    {
        if (count($this->reviewerInputs) <= 1) {
            return;
        }
        $this->reviewerInputs = array_values(array_filter(
            $this->reviewerInputs,
            static fn (array $reviewer): bool => $reviewer['id'] !== $slotId,
        ));
        $this->invalidatePreview();
    }

    public function updatedImplementationProfile(string $profileId): void
    {
        try {
            $profile = app(AgentProfileRegistry::class)->get($profileId);
        } catch (AgentProfileSelectionException $exception) {
            $this->addError('preview', 'Die Agentenprofilauswahl wurde abgelehnt: '.$exception->reason->value.'.');

            return;
        }
        $this->implementationModel = $profile->models[0];
        $this->implementationEffort = $profile->efforts[0];
    }

    public function updatedReviewerInputs(mixed $value, string $key): void
    {
        if (preg_match('/\A([0-9]+)\.profile\z/D', $key, $matches) !== 1 || ! is_string($value)) {
            return;
        }
        $index = (int) $matches[1];
        if (! isset($this->reviewerInputs[$index])) {
            return;
        }
        try {
            $profile = app(AgentProfileRegistry::class)->get($value);
        } catch (AgentProfileSelectionException $exception) {
            $this->addError('preview', 'Die Reviewer-Profilauswahl wurde abgelehnt: '.$exception->reason->value.'.');

            return;
        }
        $this->reviewerInputs[$index]['model'] = $profile->models[0];
        $this->reviewerInputs[$index]['effort'] = $profile->efforts[0];
    }

    public function updated(string $property): void
    {
        if ($property !== 'previewId') {
            $this->previewId = null;
        }
    }

    public function requestPreview(): void
    {
        Gate::authorize('approveTicket', $this->project);
        $actor = Auth::user();
        if (! $actor instanceof User) {
            abort(403);
        }
        try {
            $selection = $this->selection();
        } catch (AgentProfileSelectionException $exception) {
            $this->addError('preview', 'Die Agentenprofilauswahl wurde abgelehnt: '.$exception->reason->value.'.');

            return;
        } catch (PromptRenderingException $exception) {
            $this->addError('preview', 'Das Review-Promptprofil wurde abgelehnt: '.$exception->reason->value.'.');

            return;
        } catch (ReviewSubjectException $exception) {
            $this->addError('preview', 'Der Reviewgegenstand wurde abgelehnt: '.$exception->reason.'.');

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->addError('preview', $exception->getMessage());

            return;
        }
        $previewId = (string) Str::uuid();
        DB::transaction(function () use ($previewId, $selection, $actor): void {
            TicketApprovalPreview::query()->create([
                'id' => $previewId,
                'project_id' => $this->project->getKey(),
                'ticket_read_model_id' => $this->ticketReadModel->getKey(),
                'reviewed_ticket_blob_sha' => $this->ticketReadModel->blob_sha,
                'reviewed_control_sha' => $this->ticketReadModel->control_commit,
                'ticket_contract_sha256' => $this->ticketReadModel->ticket_contract_sha256,
                'control_generation' => $this->ticketReadModel->control_generation,
                'selection_snapshot' => $selection->jsonSerialize(),
                'state' => 'queued',
                'requested_by' => $actor->getKey(),
            ]);
            Queue::connection('database')->push(new BuildTicketApprovalPreview($previewId));
        });
        $this->previewId = $previewId;
    }

    public function render(): View
    {
        $profiles = app(AgentProfileRegistry::class);
        $catalog = app(PromptCatalog::class);
        $preview = null;
        $previewRecord = $this->previewId === null ? null : TicketApprovalPreview::query()
            ->whereKey($this->previewId)
            ->where('project_id', $this->project->getKey())
            ->where('ticket_read_model_id', $this->ticketReadModel->getKey())
            ->first();
        $previewError = $previewRecord?->state === 'conflict'
            ? 'Der Snapshot konnte nicht gebunden werden: '.($previewRecord->error_code ?? 'preview_snapshot_failed')
            : null;
        $instructionEntries = [];
        if ($previewRecord?->state === 'ready' && is_array($previewRecord->approval_snapshot)) {
            $preview = ApprovalSnapshot::fromArray($previewRecord->approval_snapshot);
            foreach ($preview->instructions as $provider => $snapshot) {
                foreach ($snapshot['entries'] as $entry) {
                    $entry['provider_profile'] = $provider;
                    $entry['content_diff'] = "--- /dev/null\n+++ ".$entry['repository_path']."\n".implode("\n", array_map(static fn (string $line): string => '+'.$line, preg_split('/\R/u', $entry['effective_content']) ?: []));
                    $instructionEntries[] = $entry;
                }
            }
        }

        return view('approvals.ticket', [
            'profiles' => $profiles->all(),
            'promptCatalog' => $catalog,
            'attentionUsers' => ProjectMembership::query()->with('user')->where('project_id', $this->project->getKey())->get()->pluck('user')->filter(fn ($user) => $user->is_active),
            'preview' => $preview,
            'previewError' => $previewError,
            'previewState' => $previewRecord?->state,
            'instructionEntries' => $instructionEntries,
            'stepUpAction' => TicketApprovalController::STEP_UP_ACTION,
        ]);
    }

    private function selection(): ApprovalSelection
    {
        $profiles = app(AgentProfileRegistry::class);

        $runType = RunType::tryFrom($this->runType) ?? RunType::IMPLEMENTATION;
        $subjectReference = null;
        $completionMode = null;
        if ($runType === RunType::REVIEW_ONLY) {
            $binding = app(ApprovalSelectionFactory::class)->reviewOnlyBinding($this->ticketReadModel, [
                'kind' => $this->reviewSubjectKind,
                'base_oid' => $this->reviewBaseOid,
                'source_oid' => $this->reviewSourceOid,
                'ref' => $this->reviewSourceRef,
                'source_run_id' => $this->reviewSourceRunId,
                'tree_oid' => $this->reviewTreeOid,
                'diff_hash' => $this->reviewDiffHash,
                'completion_mode' => $this->completionMode,
            ]);
            $subjectReference = $binding['reference'];
            $completionMode = $binding['completion_mode'];
        }

        return new ApprovalSelection(
            $profiles->resolve($this->implementationProfile, AgentRole::IMPLEMENTATION, $this->implementationModel, $this->implementationEffort),
            app(ReviewerSlotFactory::class)->fromArray($this->reviewerInputs),
            ApprovalLimits::fromConfiguredValues($this->limitInputs, app(AgentInputLimits::class)),
            $this->attentionUserId === '' ? null : (int) $this->attentionUserId,
            $this->pushMode,
            $runType,
            $subjectReference,
            $completionMode,
        );
    }

    private function invalidatePreview(): void
    {
        $this->previewId = null;
    }
}
