<?php

namespace App\AI6\Runs;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\InstructionRecommendation;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The read-only base run page.
 *
 * It reads persisted redacted state only. The route parameter is deliberately
 * named runId and not run: a parameter that matches a public model property
 * would be resolved by Livewire's implicit binding before the policy middleware
 * decides, which turns authorization into a lookup result.
 */
#[Layout('layouts.app', ['title' => 'Run-Timeline – AI6'])]
final class RunTimelinePage extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public string $runId = '';

    #[Url]
    public string $reviewerFilter = '';

    #[Url]
    public string $dispositionFilter = '';

    public function mount(Project $project, string $runId): void
    {
        Gate::authorize('viewRun', $project);
        $this->project = $project;
        $this->runId = $this->run($project, $runId)->id;
    }

    /**
     * The five server-owned decision reasons of a scope decision, as persisted
     * under the closed guard of the scope_decisions table.
     *
     * @var array<string, string>
     */
    private const DECISION_REASONS = [
        'auto_allow' => 'automatisch risikoarm nach scope.auto_allow',
        'unlisted_auto_allow' => 'nicht gelisteter Pfad nach scope.unlisted_paths',
        'human_approved' => 'menschlich genehmigt',
        'human_rejected' => 'menschlich abgelehnt',
        'amendment' => 'per Vertragsänderung aufgenommen',
    ];

    public function render(Redactor $redactor, RunLimitPolicy $limits, EffectiveFindingState $findingState): View
    {
        Gate::authorize('viewRun', $this->project);
        $run = $this->run($this->project, $this->runId);

        $summary = $this->latestImplementationSummary((string) $run->getKey());
        $payload = $summary === null ? [] : $this->readArtifactPayload($summary);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-timeline');
        $redact = static fn (string $value): string => $redactor->redact($value, $context)->text;

        $initialScope = ($run->scope_snapshot ?? [])['ticket_files'] ?? [];
        $scopeDecisions = [];
        foreach (ScopeDecision::query()->where('run_id', $run->getKey())->orderBy('created_at')->orderBy('path')->get() as $decision) {
            $scopeDecisions[] = [
                'path' => $redact($decision->path),
                'outcome' => $decision->outcome,
                // The named decision reason is persisted server-side under a
                // closed value set; deriving it from the presence of a bound
                // request would collapse auto_allow with unlisted_auto_allow
                // and show an amendment adoption as risk-free automation
                // (TKT-007, plan §8.2).
                'reason' => $decision->reason,
            ];
        }
        $quarantined = [];
        foreach (RunArtifact::query()->where('run_id', $run->getKey())
            ->where('kind', RunArtifactKind::QUARANTINED_PATH->value)
            ->orderBy('sequence')->get() as $artifact) {
            $quarantined[] = [
                'path' => (string) ($artifact->redacted_metadata['path'] ?? ''),
                'change' => (string) ($artifact->redacted_metadata['change'] ?? ''),
            ];
        }
        $reviewBlockers = [];
        foreach ($run->review_blockers ?? [] as $blocker) {
            if (is_array($blocker) && is_string($blocker['code'] ?? null) && is_string($blocker['message'] ?? null)) {
                $reviewBlockers[] = ['code' => $blocker['code'], 'message' => $blocker['message']];
            }
        }
        $findingRows = [];
        $reviewers = [];
        foreach (RunAgent::query()->where('run_id', $run->id)->where('role', 'quality_review')->orderBy('slot_id')->get() as $reviewer) {
            $reviewers[$reviewer->slot_id] = $reviewer->provider_profile.' · '.$reviewer->model.' · '.$reviewer->effort;
        }
        /** @var Collection<int, Finding> $findings */
        $findings = Finding::query()->with(['dispositions', 'statuses'])->where('run_id', $run->id)
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('created_at')->get();
        foreach ($findings as $finding) {
            $effective = $findingState->currentDisposition($finding, $run);
            $effectiveValue = $effective?->type->value ?? $finding->original_disposition->value;
            if ($this->reviewerFilter !== '' && $finding->slot_id !== $this->reviewerFilter) {
                continue;
            }
            if ($this->dispositionFilter !== '' && $effectiveValue !== $this->dispositionFilter) {
                continue;
            }
            $history = [];
            foreach ($finding->dispositions->sortBy('expected_run_version') as $disposition) {
                $history[] = [
                    'type' => $disposition->type->value,
                    'source' => $disposition->decision_source->value,
                    'evidence_review_result_id' => $disposition->evidence_review_result_id,
                    'reason' => $redact($disposition->reason),
                    'effective' => $effective?->id === $disposition->id,
                ];
            }
            $statusHistory = [];
            foreach ($finding->statuses->sortBy('slot_id')->sortBy('round_number') as $status) {
                $statusHistory[] = [
                    'round' => $status->round_number,
                    'slot_id' => $status->slot_id,
                    // The fix turn's own assessment is no reviewer slot; it is named
                    // by its role so the rejection stays readable evidence (AC-07).
                    'source' => $status->source_role === 'implementation'
                        ? 'Implementierungsagent'
                        : ($reviewers[$status->slot_id] ?? $status->slot_id),
                    'source_role' => $status->source_role,
                    'status' => $status->status->value,
                    'evidence' => $redact($status->evidence),
                    'checkpoint_tree' => $status->checkpoint_tree_sha,
                ];
            }
            $findingRows[] = [
                'id' => $finding->id,
                'slot_id' => $finding->slot_id,
                'source' => $reviewers[$finding->slot_id] ?? $finding->slot_id,
                'round' => $finding->round_number,
                'checkpoint_tree' => $finding->checkpoint_tree_sha,
                'diff_hash' => $finding->diff_hash,
                'severity' => $finding->severity->value,
                'original_disposition' => $finding->original_disposition->value,
                'effective_disposition' => $effectiveValue,
                'blocks' => $findingState->blocks($finding, $run, $effective),
                'category' => $finding->category->value,
                'file' => $redact($finding->file),
                'line' => $finding->line,
                'title' => $redact($finding->title),
                'evidence' => $redact($finding->evidence),
                'expected_result' => $redact($finding->expected_result),
                'criterion_refs' => $finding->criterion_refs,
                'duplicate_group' => $finding->duplicate_group,
                'history' => $history,
                'status_history' => $statusHistory,
            ];
        }
        /** @var Collection<int, CriterionCoverage> $coverage */
        $coverage = CriterionCoverage::query()->where('run_id', $run->id)
            ->when($this->reviewerFilter !== '', fn ($query) => $query->where('slot_id', $this->reviewerFilter))
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('criterion_id')->get();
        $coverageRows = $coverage->map(fn (CriterionCoverage $entry): array => [
            'slot_id' => $entry->slot_id,
            'source' => $reviewers[$entry->slot_id] ?? $entry->slot_id,
            'criterion_id' => $entry->criterion_id,
            'status' => $entry->status,
            'evidence' => $redact($entry->evidence),
        ])->all();
        /** @var Collection<int, InstructionRecommendation> $recommendationModels */
        $recommendationModels = InstructionRecommendation::query()->where('run_id', $run->id)
            ->when($this->reviewerFilter !== '', fn ($query) => $query->where('slot_id', $this->reviewerFilter))
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('created_at')->get();
        $recommendations = $recommendationModels->map(fn (InstructionRecommendation $entry): array => [
            'source' => $reviewers[$entry->slot_id] ?? $entry->slot_id,
            'title' => $redact($entry->title),
            'recommendation' => $redact($entry->recommendation),
            'reason' => $redact($entry->reason),
        ])->all();

        return view('runs.timeline', [
            'run' => $run,
            'jobs' => ExecutionJob::query()->where('run_id', $run->getKey())
                ->orderBy('step_number')->orderBy('id')->get(),
            'sessions' => RunAgent::query()->where('run_id', $run->getKey())->orderBy('id')->get(),
            'events' => RunEvent::query()->where('run_id', $run->getKey())->orderBy('id')->get(),
            'changedFiles' => is_array($payload['changed_files'] ?? null) ? $payload['changed_files'] : [],
            'decisions' => is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [],
            'initialScope' => array_map($redact, array_values(array_filter(is_array($initialScope) ? $initialScope : [], 'is_string'))),
            'scopeDecisions' => $scopeDecisions,
            'scopeDecisionReasons' => self::DECISION_REASONS,
            'quarantinedPaths' => $quarantined,
            'addedScopePathsUsed' => $run->added_scope_paths_count,
            'addedScopePathsLimit' => $limits->effective($run)['max_added_scope_paths'] ?? null,
            'reviewReadinessState' => $run->review_readiness_state,
            'reviewBlockers' => $reviewBlockers,
            'checkResults' => CheckResultRecord::query()->where('run_id', $run->getKey())
                ->whereNull('superseded_at')->orderBy('phase')->orderBy('profile')->get(),
            'runGates' => RunGate::query()->where('run_id', $run->getKey())->orderBy('gate_id')->get(),
            'findingRows' => $findingRows,
            'coverageRows' => $coverageRows,
            'instructionRecommendations' => $recommendations,
            'reviewers' => $reviewers,
            'canDisposeFindings' => Gate::allows('disposeFinding', $this->project),
            'completionReport' => RunArtifact::query()->where('run_id', $run->id)
                ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->first(),
            'manualReportRequest' => HumanRequest::query()->where('run_id', $run->id)
                ->where('kind', WaitReason::MANUAL_REPORT->value)
                ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->first(),
        ]);
    }

    private function run(Project $project, string $runId): Run
    {
        return Run::query()->whereKey($runId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }

    private function latestImplementationSummary(string $runId): ?RunArtifact
    {
        /** @var RunArtifact|null $artifact */
        $artifact = RunArtifact::query()->where('run_id', $runId)
            ->where('kind', RunArtifactKind::IMPLEMENTATION_SUMMARY->value)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->first();

        return $artifact;
    }

    /** @return array<string, mixed> */
    private function readArtifactPayload(RunArtifact $artifact): array
    {
        $root = config('ai6.run_artifacts.root');
        if (! is_string($root) || $root === '') {
            return $artifact->redacted_metadata;
        }
        $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifact->storage_reference);
        if (! is_file($path) || is_link($path)) {
            return [];
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return [];
        }
        try {
            $decoded = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
