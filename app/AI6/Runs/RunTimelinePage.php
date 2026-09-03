<?php

namespace App\AI6\Runs;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\InstructionRecommendation;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Markdown\SafeTextRenderer;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityPolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The read-only run page: overview, diff, checks, findings, human requests,
 * security, push, artifacts and timeline (UI-004).
 *
 * It reads persisted state only. Untrusted text is presented through the
 * fixed order of SEC-007: text persisted behind the central redaction gets
 * the presentation sanitization only, raw text gets the central redaction
 * exactly once and the presentation sanitization exactly once afterwards.
 * Polling re-reads and compares cursors; it never mutates anything.
 *
 * The route parameter is deliberately named runId and not run: a parameter
 * that matches a public model property would be resolved by Livewire's
 * implicit binding before the policy middleware decides, which turns
 * authorization into a lookup result.
 */
#[Layout('layouts.app', ['title' => 'Run-Timeline – AI6'])]
final class RunTimelinePage extends Component
{
    public const CHANGED_FILES_PAGE_SIZE = 50;

    public const FINDINGS_PAGE_SIZE = 20;

    public const ARTIFACTS_PAGE_SIZE = 20;

    public const EVENTS_PAGE_SIZE = 100;

    public const LOG_EXCERPT_BYTES = 8192;

    public const TEXT_EXCERPT_BYTES = 2048;

    public const DIFF_EXCERPT_BYTES = 65536;

    #[Locked]
    public Project $project;

    #[Locked]
    public string $runId = '';

    #[Url]
    public string $reviewerFilter = '';

    #[Url]
    public string $dispositionFilter = '';

    #[Url]
    public int $changedFilesPage = 1;

    #[Url]
    public int $findingsPage = 1;

    #[Url]
    public int $artifactsPage = 1;

    /** 0 selects the newest timeline page; an explicit page number reads older ones. */
    #[Url]
    public int $eventsPage = 0;

    /** The run version the last render showed; polling compares against it. */
    #[Locked]
    public int $seenVersion = 0;

    /** The highest timeline event id the last render showed; monotonic. */
    #[Locked]
    public int $eventCursor = 0;

    /** The latest artifact change the last render showed, as a Unix timestamp; monotonic. */
    #[Locked]
    public int $artifactCursor = 0;

    /**
     * A digest over every other persisted state the page shows — requests,
     * interventions, checks, findings, gates, slots, approval — so a change
     * without a new event or version still re-renders on the next poll.
     */
    #[Locked]
    public string $observationDigest = '';

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

    public function mount(Project $project, string $runId): void
    {
        Gate::authorize('viewRun', $project);
        $this->project = $project;
        $this->runId = $this->run($project, $runId)->id;
    }

    /**
     * The polling refresh: a pure read that re-authorizes, compares the run
     * version and the monotonic cursors with what the client last saw and
     * skips the re-render when nothing changed. It repeats no action.
     */
    public function poll(): void
    {
        Gate::authorize('viewRun', $this->project);
        $run = $this->run($this->project, $this->runId);
        [$eventCursor, $artifactCursor, $digest] = $this->cursors($run, CarbonImmutable::instance(Date::now()));
        if ($run->version === $this->seenVersion && $eventCursor === $this->eventCursor
            && $artifactCursor === $this->artifactCursor && hash_equals($this->observationDigest, $digest)) {
            $this->skipRender();
        }
    }

    public function render(
        SafeTextRenderer $renderer,
        RunLimitPolicy $limits,
        EffectiveFindingState $findingState,
        RetentionPolicy $retention,
        RunArtifactStore $artifactStore,
        SecurityPolicy $security,
    ): View {
        Gate::authorize('viewRun', $this->project);
        $run = $this->run($this->project, $this->runId);
        $now = CarbonImmutable::instance(Date::now());
        $this->seenVersion = $run->version;
        [$this->eventCursor, $this->artifactCursor, $this->observationDigest] = $this->cursors($run, $now);

        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-timeline');
        // Persisted-redacted text: presentation sanitization only, never a
        // second redaction. Raw text: central redaction once, sanitization once.
        $present = static fn (string $value): string => $renderer->present($value);
        $render = static fn (string $value): string => $renderer->render($value, $context);
        $effectiveLimits = $limits->effective($run);
        $approval = TicketApproval::query()->find($run->ticket_approval_id);

        // From expiry on the summary bytes are locked for every output path,
        // whether or not the retention run already removed them; the store's
        // bytes() is the one predicate, the flag only names the reason.
        $summary = $this->latestImplementationSummary((string) $run->getKey());
        $summaryUnavailable = null;
        if ($summary !== null && $summary->isDeleted()) {
            $summaryUnavailable = 'deleted';
        } elseif ($summary !== null && $summary->isExpired($now)) {
            $summaryUnavailable = 'expired';
        }
        $payload = $summary === null || $summaryUnavailable !== null ? [] : $this->readArtifactPayload($artifactStore, $summary);

        // The textual diff of the bound checkpoint comes from its own
        // artifact, persisted redacted by the worker; the store's bytes() is
        // the one lock from expiry on, the metadata names why it is missing.
        $checkpointDiff = null;
        $diffArtifact = CheckpointDiffRecorder::boundArtifact($run);
        if ($diffArtifact instanceof RunArtifact) {
            $unavailable = $diffArtifact->isDeleted() ? 'deleted' : ($diffArtifact->isExpired($now) ? 'expired' : null);
            $recorded = $diffArtifact->redacted_metadata['unavailable'] ?? null;
            if ($unavailable === null && is_string($recorded) && in_array($recorded, ['not_utf8', 'git_output_unavailable'], true)) {
                $unavailable = $recorded;
            }
            $bytes = $unavailable === null ? $artifactStore->bytes($diffArtifact) : null;
            $checkpointDiff = [
                'artifact_id' => $diffArtifact->id,
                'unavailable' => $unavailable,
                'stored_truncated' => ($diffArtifact->redacted_metadata['truncated'] ?? false) === true,
                'total_bytes' => (int) ($diffArtifact->redacted_metadata['total_bytes'] ?? 0),
                'text' => $bytes === null ? null : $this->excerpt($present($bytes), self::DIFF_EXCERPT_BYTES),
            ];
        }

        $initialScope = ($run->scope_snapshot ?? [])['ticket_files'] ?? [];
        $scopeDecisions = [];
        foreach (ScopeDecision::query()->where('run_id', $run->getKey())->orderBy('created_at')->orderBy('path')->get() as $decision) {
            $scopeDecisions[] = [
                'path' => $render($decision->path),
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
                'path' => $present((string) ($artifact->redacted_metadata['path'] ?? '')),
                'change' => $present((string) ($artifact->redacted_metadata['change'] ?? '')),
            ];
        }
        $reviewBlockers = [];
        foreach ($run->review_blockers ?? [] as $blocker) {
            if (is_array($blocker) && is_string($blocker['code'] ?? null) && is_string($blocker['message'] ?? null)) {
                $reviewBlockers[] = ['code' => $blocker['code'], 'message' => $present($blocker['message'])];
            }
        }

        $agentSlots = ['implementation' => [], 'quality_review' => [], 'finding_verification' => [], 'security_review' => []];
        $reviewers = [];
        foreach (RunAgent::query()->where('run_id', $run->id)->orderBy('role')->orderBy('slot_id')->orderBy('slot_revision')->get() as $agent) {
            $label = $present($agent->provider_profile.' · '.$agent->model.' · '.$agent->effort);
            $agentSlots[$agent->role][] = [
                'slot_id' => $agent->slot_id,
                'label' => $label,
                'prompt_profile' => $present($agent->prompt_profile),
                'revision' => $agent->slot_revision,
                'active' => $agent->is_active,
                'bound' => $agent->session_id !== null,
            ];
            if ($agent->role === 'quality_review') {
                $reviewers[$agent->slot_id] = $label;
            }
        }

        // The effective disposition is a decision over the dispositions, not a
        // column, so the filter runs over all findings of the run; the texts,
        // histories and verifications are loaded and prepared for the shown
        // page only. Reviewer filter, disposition filter and order stay as they
        // are, and the total counts every finding the filter admits.
        $findingRows = [];
        /** @var Collection<int, Finding> $findings */
        $findings = Finding::query()->with(['dispositions'])->where('run_id', $run->id)
            ->when($this->reviewerFilter !== '', fn ($query) => $query->where('slot_id', $this->reviewerFilter))
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('created_at')->get();
        $selected = [];
        foreach ($findings as $finding) {
            $effective = $findingState->currentDisposition($finding, $run);
            $effectiveValue = $effective?->type->value ?? $finding->original_disposition->value;
            if ($this->dispositionFilter !== '' && $effectiveValue !== $this->dispositionFilter) {
                continue;
            }
            $selected[] = [$finding, $effective, $effectiveValue];
        }
        $findingPage = $this->pageOf(count($selected), $this->findingsPage, self::FINDINGS_PAGE_SIZE);
        $selected = array_slice($selected, ($findingPage['page'] - 1) * self::FINDINGS_PAGE_SIZE, self::FINDINGS_PAGE_SIZE);
        (new Collection(array_map(static fn (array $entry): Finding => $entry[0], $selected)))->load(['statuses', 'verifications']);
        $verificationResults = $selected === [] ? new Collection : ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'finding_verification')->where('invocation_outcome', 'valid_result')
            ->orderBy('round_number')->orderBy('created_at')->get();
        foreach ($selected as [$finding, $effective, $effectiveValue]) {
            $history = [];
            foreach ($finding->dispositions->sortBy('expected_run_version') as $disposition) {
                $history[] = [
                    'type' => $disposition->type->value,
                    'source' => $disposition->decision_source->value,
                    'evidence_review_result_id' => $disposition->evidence_review_result_id,
                    // The human reason is raw user input; it crosses the central
                    // redaction here, exactly once, before the presentation step.
                    'reason' => $this->excerpt($render($disposition->reason), self::TEXT_EXCERPT_BYTES),
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
                    'evidence' => $this->excerpt($present($status->evidence), self::TEXT_EXCERPT_BYTES),
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
                'file' => $present($finding->file),
                'line' => $finding->line,
                'title' => $present($finding->title),
                'evidence' => $this->excerpt($present($finding->evidence), self::TEXT_EXCERPT_BYTES),
                'expected_result' => $this->excerpt($present($finding->expected_result), self::TEXT_EXCERPT_BYTES),
                'criterion_refs' => $finding->criterion_refs,
                'duplicate_group' => $finding->duplicate_group,
                'history' => $history,
                'status_history' => $statusHistory,
                'verifications' => array_values(array_map(fn (ReviewResult $result): array => [
                    'round' => $result->round_number,
                    'source' => $present($result->provider_profile.' · '.$result->model.' · '.$result->effort),
                    'assessment' => $result->verification_assessment,
                    'recommendation' => $result->verification_recommendation,
                    'evidence' => $this->excerpt($present((string) $result->verification_evidence), self::TEXT_EXCERPT_BYTES),
                    'checkpoint_tree' => $result->checkpoint_tree_sha,
                    'diff_hash' => $result->diff_hash,
                ], $finding->verifications
                    ->where('role', 'finding_verification')
                    ->where('invocation_outcome', 'valid_result')
                    ->concat($verificationResults->where('original_duplicate_group', $finding->duplicate_group))
                    ->unique('id')->values()->all())),
            ];
        }
        $findingPage['items'] = $findingRows;

        /** @var Collection<int, CriterionCoverage> $coverage */
        $coverage = CriterionCoverage::query()->where('run_id', $run->id)
            ->when($this->reviewerFilter !== '', fn ($query) => $query->where('slot_id', $this->reviewerFilter))
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('criterion_id')->get();
        $coverageRows = $coverage->map(fn (CriterionCoverage $entry): array => [
            'slot_id' => $entry->slot_id,
            'source' => $reviewers[$entry->slot_id] ?? $entry->slot_id,
            'criterion_id' => $entry->criterion_id,
            // The coverage status is free provider text persisted without
            // redaction; it crosses the central redaction here, exactly once.
            'status' => $render($entry->status),
            'evidence' => $this->excerpt($present($entry->evidence), self::TEXT_EXCERPT_BYTES),
        ])->all();
        /** @var Collection<int, InstructionRecommendation> $recommendationModels */
        $recommendationModels = InstructionRecommendation::query()->where('run_id', $run->id)
            ->when($this->reviewerFilter !== '', fn ($query) => $query->where('slot_id', $this->reviewerFilter))
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('created_at')->get();
        $recommendations = $recommendationModels->map(fn (InstructionRecommendation $entry): array => [
            'source' => $reviewers[$entry->slot_id] ?? $entry->slot_id,
            'title' => $present($entry->title),
            'recommendation' => $this->excerpt($present($entry->recommendation), self::TEXT_EXCERPT_BYTES),
            'reason' => $this->excerpt($present($entry->reason), self::TEXT_EXCERPT_BYTES),
        ])->all();

        $changedFiles = [];
        foreach (is_array($payload['changed_files'] ?? null) ? $payload['changed_files'] : [] as $change) {
            if (! is_array($change)) {
                continue;
            }
            $changedFiles[] = [
                'path' => $present((string) ($change['path'] ?? '')),
                'change' => $present((string) ($change['change'] ?? '')),
                'bytes' => is_int($change['bytes'] ?? null) ? $change['bytes'] : null,
            ];
        }
        $decisions = [];
        foreach (is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [] as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $decisions[] = [
                'key' => $present((string) ($decision['key'] ?? '')),
                'title' => $present((string) ($decision['title'] ?? '')),
                'rationale' => $this->excerpt($present((string) ($decision['rationale'] ?? '')), self::TEXT_EXCERPT_BYTES),
            ];
        }

        $checkLogs = $retention->limit(RetentionCategory::CHECK_LOGS);
        // The check output is persisted by the runner; its trusted size limit
        // therefore binds the shown excerpt, never below the page's own bound.
        $checkExcerptBytes = min(self::LOG_EXCERPT_BYTES, $checkLogs->maxBytes);
        $checkRows = [];
        /** @var Collection<int, CheckResultRecord> $checkResults */
        $checkResults = CheckResultRecord::query()->where('run_id', $run->getKey())
            ->whereNull('superseded_at')->orderBy('phase')->orderBy('profile')->get();
        foreach ($checkResults as $result) {
            // Provenance is the bound tombstone column, never the payload: an
            // untrusted output that prints the marker stays visible text.
            $tombstone = $result->isTombstone();
            $retentionState = $this->retentionOf($checkLogs, $now, $result->retention_deleted_at, $result->retention_expires_at, $result->fingerprint_key_id, $result->fingerprint_version);
            $checkRows[] = [
                'profile' => $result->profile,
                'phase' => $result->phase->value,
                'state' => $result->state->value,
                'reason' => $result->reason === null ? null : $present($result->reason),
                'exit_code' => $result->exit_code,
                'duration_ms' => $result->duration_ms,
                'tree_sha' => $result->tree_sha,
                'output' => $tombstone || $retentionState['expired'] ? null : $this->excerpt($present($result->redacted_output), $checkExcerptBytes),
                'retention' => $retentionState,
            ];
        }

        $humanRequests = [];
        $openRequests = [];
        foreach (HumanRequest::query()->where('run_id', $run->id)->get()->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $request) {
            $intervention = $request->intervention()->first();
            $row = [
                'id' => $request->id,
                'kind' => $request->kind,
                'title' => $present($request->title),
                'message' => $this->excerpt($present($request->message), self::TEXT_EXCERPT_BYTES),
                'resolution_state' => $request->resolution_state->value,
                'delivery_status' => $request->delivery_status->value,
                'delivery_failure_key' => $request->delivery_failure_key,
                'bound_run_version' => $request->bound_run_version,
                'created_at' => $request->created_at,
                'resolved_at' => $request->resolved_at,
                'chosen_effect' => $intervention instanceof Intervention ? $intervention->chosen_effect : null,
            ];
            $humanRequests[] = $row;
            if ($request->resolution_state === HumanRequestResolutionState::OPEN) {
                $openRequests[] = $row;
            }
        }

        $securityReview = ReviewResult::query()->where('run_id', $run->id)->where('role', 'security_review')
            ->get()->sortBy([['round_number', 'desc'], ['created_at', 'desc']])->first();

        $artifactLimits = [];
        foreach (RetentionCategory::cases() as $category) {
            $artifactLimits[$category->value] = $retention->limit($category);
        }
        $artifactRows = [];
        foreach (RunArtifact::query()->where('run_id', $run->id)->get()->sortBy('sequence') as $artifact) {
            $category = RetentionCategory::forArtifactKind($artifact->kind);
            $deleted = $artifact->isDeleted();
            $expired = $artifact->isExpired($now);
            $artifactRows[] = [
                'id' => $artifact->id,
                'kind' => $artifact->kind->value,
                'sequence' => $artifact->sequence,
                'category' => $category->value,
                'size_bytes' => $artifact->size_bytes,
                'created_at' => $artifact->created_at,
                'expires_at' => $artifact->expires_at,
                'remaining_days' => $this->remainingDays($now, $artifact->expires_at),
                'deleted' => $deleted,
                'expired' => $expired,
                'deleted_at' => $artifact->deleted_at,
                'fingerprint_key_id' => $artifact->fingerprint_key_id,
                'fingerprint_version' => $artifact->fingerprint_version,
                'fingerprint' => $artifact->fingerprint,
                'downloadable' => ! $deleted && ! $expired && ! $artifactLimits[$category->value]->exceeds($artifact->size_bytes),
            ];
        }
        $artifactPage = $this->paginate($artifactRows, $this->artifactsPage, self::ARTIFACTS_PAGE_SIZE);

        $runLogs = $retention->limit(RetentionCategory::RUN_LOGS);
        $eventExcerptBytes = min(self::LOG_EXCERPT_BYTES, $runLogs->maxBytes);
        // The newest page is the default: a live run is watched at its end.
        // Only the shown page is loaded, sanitized and excerpted.
        $eventRows = [];
        $eventPage = $this->pageOf(RunEvent::query()->where('run_id', $run->getKey())->count(), $this->eventsPage, self::EVENTS_PAGE_SIZE);
        /** @var Collection<int, RunEvent> $events */
        $events = RunEvent::query()->where('run_id', $run->getKey())->orderBy('id')
            ->skip(($eventPage['page'] - 1) * self::EVENTS_PAGE_SIZE)->take(self::EVENTS_PAGE_SIZE)->get();
        foreach ($events as $event) {
            $tombstone = $event->isTombstone();
            $retentionState = $this->retentionOf($runLogs, $now, $event->retention_deleted_at, $event->retention_expires_at, $event->fingerprint_key_id, $event->fingerprint_version);
            $eventRows[] = [
                'id' => $event->id,
                'created_at' => $event->created_at,
                'event_type' => $present($event->event_type),
                'payload' => $tombstone || $retentionState['expired'] ? null : $this->excerpt($present($event->redacted_payload), $eventExcerptBytes),
                'retention' => $retentionState,
            ];
        }
        $eventPage['items'] = $eventRows;

        // Before the implementation turn materializes its slot, the approved
        // selection from the bound snapshot names the model and effort.
        $approvedImplementation = ($run->agent_profile_snapshot ?? [])['implementation'] ?? [];
        $plannedImplementation = null;
        if (is_array($approvedImplementation)
            && is_string($approvedImplementation['provider_profile'] ?? null)
            && is_string($approvedImplementation['model'] ?? null)
            && is_string($approvedImplementation['effort'] ?? null)) {
            $plannedImplementation = $present($approvedImplementation['provider_profile'].' · '.$approvedImplementation['model'].' · '.$approvedImplementation['effort']);
        }

        // Likewise the approved reviewer selection names the reviewer models
        // and efforts until the first review round materializes its slots.
        $plannedReviewers = [];
        foreach (($run->agent_profile_snapshot ?? [])['reviewers'] ?? [] as $reviewer) {
            if (is_array($reviewer)
                && is_string($reviewer['provider_profile'] ?? null)
                && is_string($reviewer['model'] ?? null)
                && is_string($reviewer['effort'] ?? null)) {
                $plannedReviewers[] = [
                    'label' => $present($reviewer['provider_profile'].' · '.$reviewer['model'].' · '.$reviewer['effort']),
                    'prompt_profile' => $present((string) ($reviewer['prompt_profile_id'] ?? '')),
                ];
            }
        }

        $jobs = ExecutionJob::query()->where('run_id', $run->getKey())->orderBy('step_number')->orderBy('id')->get();
        $rounds = [];
        foreach (['review' => 'max_review_rounds', 'fix' => 'max_fix_rounds', 'verify' => 'max_verification_rounds'] as $stepType => $limitName) {
            $rounds[$stepType] = [
                'used' => (int) $jobs->where('step_type', $stepType)->max('step_number'),
                'limit' => $effectiveLimits[$limitName] ?? null,
            ];
        }

        return view('runs.timeline', [
            'run' => $run,
            'approval' => $approval,
            // The ticket id originates in untrusted ticket markdown.
            'ticketId' => $approval === null ? null : $render($approval->ticket_id),
            'jobs' => $jobs,
            'rounds' => $rounds,
            'agentSlots' => $agentSlots,
            'plannedImplementation' => $plannedImplementation,
            'plannedReviewers' => $plannedReviewers,
            'openRequests' => $openRequests,
            'humanRequests' => $humanRequests,
            'canAnswerRequests' => Gate::allows('answerHumanRequest', $this->project),
            'securityBanner' => $security->bannerData(),
            'securityReview' => $securityReview,
            'changedFiles' => $changedFiles,
            // Named apart from the changedFilesPage property that Livewire also hands to the view.
            'changedFilePage' => $this->paginate($changedFiles, $this->changedFilesPage, self::CHANGED_FILES_PAGE_SIZE),
            'summaryUnavailable' => $summaryUnavailable,
            'checkpointDiff' => $checkpointDiff,
            'decisions' => $decisions,
            'initialScope' => array_map($render, array_values(array_filter(is_array($initialScope) ? $initialScope : [], 'is_string'))),
            'scopeDecisions' => $scopeDecisions,
            'scopeDecisionReasons' => self::DECISION_REASONS,
            'quarantinedPaths' => $quarantined,
            'addedScopePathsUsed' => $run->added_scope_paths_count,
            'addedScopePathsLimit' => $effectiveLimits['max_added_scope_paths'] ?? null,
            'reviewReadinessState' => $run->review_readiness_state,
            'reviewBlockers' => $reviewBlockers,
            'checkRows' => $checkRows,
            'runGates' => RunGate::query()->where('run_id', $run->getKey())->orderBy('gate_id')->get(),
            'findingRows' => $findingPage['items'],
            'findingPage' => $findingPage,
            'coverageRows' => $coverageRows,
            'instructionRecommendations' => $recommendations,
            'reviewers' => $reviewers,
            'canDisposeFindings' => Gate::allows('disposeFinding', $this->project),
            'artifactPage' => $artifactPage,
            'artifactLimits' => $artifactLimits,
            'eventPage' => $eventPage,
            'retentionPolicy' => $retention,
            'completionReport' => RunArtifact::query()->where('run_id', $run->id)
                ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->first(),
            'manualReportRequest' => HumanRequest::query()->where('run_id', $run->id)
                ->where('kind', WaitReason::MANUAL_REPORT->value)
                ->where('resolution_state', HumanRequestResolutionState::OPEN->value)->first(),
            'pageUrl' => fn (array $overrides): string => $this->pageUrl($overrides),
            'refreshUrl' => $this->pageUrl([]),
        ]);
    }

    private function run(Project $project, string $runId): Run
    {
        return Run::query()->whereKey($runId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }

    /**
     * The monotonic event and artifact cursors plus the observation digest.
     *
     * The digest covers counts and latest change times of every persisted
     * table the page renders, so a delivery status, a retention tombstone, a
     * superseded check or a new disposition re-renders without an event. It
     * also covers the clock-dependent retention situation of every shown
     * record — expired or not, remaining days — so an expiry that locks
     * content and download links, or a day that passes, re-renders without
     * any database change.
     *
     * @return array{int, int, string}
     */
    private function cursors(Run $run, CarbonImmutable $now): array
    {
        $runId = $run->getKey();
        $eventCursor = (int) RunEvent::query()->where('run_id', $runId)->max('id');
        $latestArtifact = RunArtifact::query()->where('run_id', $runId)->max('updated_at');
        $artifactCursor = is_string($latestArtifact) && $latestArtifact !== ''
            ? Date::parse($latestArtifact)->getTimestamp()
            : 0;

        // Persisted state metadata, never the payloads: a log payload changes
        // only through its retention deletion, which the bound deletion time
        // records; a delivery outcome is its own column. Values, not only
        // change times, so a change within the same second or under a frozen
        // clock is still visible.
        $observed = [$run->version];
        foreach ([
            'run_events' => ['id', 'retention_deleted_at'],
            'run_artifacts' => ['id', 'retention_state', 'deleted_at'],
            'human_requests' => ['id', 'delivery_status', 'delivery_failure_key', 'delivery_revision', 'resolution_state'],
            'check_results' => ['id', 'superseded_at', 'retention_deleted_at'],
            'findings' => ['id'],
            'finding_statuses' => ['id'],
            'criterion_coverages' => ['id'],
            'instruction_recommendations' => ['id'],
            'review_results' => ['id', 'invocation_outcome', 'result_status'],
            'run_gates' => ['id', 'state', 'invalidated_at', 'authorized_at'],
            'run_agents' => ['id', 'is_active', 'session_id', 'slot_revision'],
            'scope_decisions' => ['id'],
            'execution_jobs' => ['id', 'state', 'attempts', 'failure_code'],
        ] as $table => $columns) {
            $rows = DB::table($table)->where('run_id', $runId)->orderBy('id')->get($columns);
            $observed[] = [$table, hash('sha256', $rows->toJson())];
        }
        $observed[] = ['finding_dispositions', DB::table('finding_dispositions')
            ->whereIn('finding_id', DB::table('findings')->where('run_id', $runId)->select('id'))->count()];
        $observed[] = ['interventions', DB::table('interventions')
            ->whereIn('human_request_id', DB::table('human_requests')->where('run_id', $runId)->select('id'))->count()];
        $observed[] = ['ticket_approvals', DB::table('ticket_approvals')->where('id', $run->ticket_approval_id)->value('version')];
        $observed[] = ['retention', $this->retentionObservation($runId, $now)];

        return [$eventCursor, $artifactCursor, hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR))];
    }

    /**
     * The clock-dependent part of the observation, over exactly the records
     * the page shows: the artifacts of the shown artifact page plus the two
     * artifacts whose content is presented on their own — the latest
     * implementation summary and the bound checkpoint diff —, the events of
     * the shown timeline page and the live check results. For each, whether
     * its retention has ended at this moment and how many days remain; both
     * change without a write, and nothing outside the shown page can change
     * what the page renders.
     *
     * @return list<array{string, int|string, bool, int|null}>
     */
    private function retentionObservation(string $runId, CarbonImmutable $now): array
    {
        $observed = [];

        $artifactPage = $this->pageOf((int) DB::table('run_artifacts')->where('run_id', $runId)->count(), $this->artifactsPage, self::ARTIFACTS_PAGE_SIZE);
        $shownArtifacts = DB::table('run_artifacts')->where('run_id', $runId)->orderBy('sequence')
            ->skip(($artifactPage['page'] - 1) * self::ARTIFACTS_PAGE_SIZE)->take(self::ARTIFACTS_PAGE_SIZE)->get(['id', 'expires_at'])->all();
        $summary = $this->latestImplementationSummary($runId);
        if ($summary instanceof RunArtifact) {
            $shownArtifacts[] = (object) ['id' => $summary->id, 'expires_at' => $summary->expires_at->format('Y-m-d H:i:s')];
        }
        $run = Run::query()->find($runId);
        $diffArtifact = $run instanceof Run ? CheckpointDiffRecorder::boundArtifact($run) : null;
        if ($diffArtifact instanceof RunArtifact) {
            $shownArtifacts[] = (object) ['id' => $diffArtifact->id, 'expires_at' => $diffArtifact->expires_at->format('Y-m-d H:i:s')];
        }
        foreach ($shownArtifacts as $row) {
            $expiresAt = CarbonImmutable::parse((string) $row->expires_at);
            $observed[] = ['run_artifacts', (string) $row->id, $expiresAt->lessThanOrEqualTo($now), $this->remainingDays($now, $expiresAt)];
        }

        $eventPage = $this->pageOf((int) DB::table('run_events')->where('run_id', $runId)->count(), $this->eventsPage, self::EVENTS_PAGE_SIZE);
        $shownEvents = DB::table('run_events')->where('run_id', $runId)->orderBy('id')
            ->skip(($eventPage['page'] - 1) * self::EVENTS_PAGE_SIZE)->take(self::EVENTS_PAGE_SIZE)->get(['id', 'retention_expires_at']);
        $liveChecks = DB::table('check_results')->where('run_id', $runId)->whereNull('superseded_at')->orderBy('id')->get(['id', 'retention_expires_at']);
        foreach (['run_events' => $shownEvents, 'check_results' => $liveChecks] as $table => $rows) {
            foreach ($rows as $row) {
                // Only the persisted expiry counts; a row without one is
                // closed for good and observes as expired without a remainder.
                $expiresAt = is_string($row->retention_expires_at) && $row->retention_expires_at !== ''
                    ? CarbonImmutable::parse($row->retention_expires_at)
                    : null;
                $observed[] = [$table, is_int($row->id) ? $row->id : (string) $row->id, $expiresAt === null || $expiresAt->lessThanOrEqualTo($now), $expiresAt === null ? null : $this->remainingDays($now, $expiresAt)];
            }
        }

        return $observed;
    }

    private function remainingDays(CarbonImmutable $now, CarbonInterface $expiresAt): int
    {
        return $expiresAt->lessThanOrEqualTo($now) ? 0 : (int) ceil($now->diffInSeconds($expiresAt) / 86400);
    }

    /**
     * Server-side pagination of an already built row list. The page is clamped
     * to the available range so a stale query string never hides everything.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{items: list<array<string, mixed>>, page: int, pages: int, total: int, from: int, to: int, size: int}
     */
    private function paginate(array $rows, int $page, int $size): array
    {
        $result = $this->pageOf(count($rows), $page, $size);
        $result['items'] = array_slice($rows, ($result['page'] - 1) * $size, $size);

        return $result;
    }

    /**
     * The page window over a total, before any row is built: the caller loads
     * and prepares exactly that window.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pages: int, total: int, from: int, to: int, size: int}
     */
    private function pageOf(int $total, int $page, int $size): array
    {
        $pages = max(1, (int) ceil($total / $size));
        // A non-positive page selects the newest page.
        $page = $page < 1 ? $pages : min($page, $pages);

        return [
            'items' => [],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'from' => $total === 0 ? 0 : ($page - 1) * $size + 1,
            'to' => min($total, $page * $size),
            'size' => $size,
        ];
    }

    /**
     * A visible, UTF-8-safe excerpt: the shown bytes and the full size are
     * named next to each other instead of cutting silently.
     *
     * @return array{text: string, truncated: bool, shown: int, total: int}
     */
    private function excerpt(string $text, int $limit): array
    {
        $total = strlen($text);
        if ($total <= $limit) {
            return ['text' => $text, 'truncated' => false, 'shown' => $total, 'total' => $total];
        }
        $shown = mb_strcut($text, 0, $limit, 'UTF-8');

        return ['text' => $shown, 'truncated' => true, 'shown' => strlen($shown), 'total' => $total];
    }

    /**
     * The retention situation of one stored record: its expiry, the remaining
     * days, or the moment retention already removed its raw content together
     * with the tombstone's provenance — the key id and version its bound
     * fingerprint was written under. Every record shows the expiry it was
     * bound to at persistence, never one re-derived from the current
     * configuration; a raised value reveals no expired raw data again. A
     * record without a persisted expiry has no trusted retention at all and
     * is closed: its raw content is not shown, and the page names that state.
     *
     * @return array{category: string, expires_at: CarbonInterface|null, expired: bool, unbound: bool, remaining_days: int|null, deleted_at: CarbonInterface|null, fingerprint_key_id: string|null, fingerprint_version: int|null}
     */
    private function retentionOf(
        RetentionLimit $limit,
        CarbonImmutable $now,
        ?CarbonInterface $deletedAt = null,
        ?CarbonInterface $boundExpiry = null,
        ?string $fingerprintKeyId = null,
        ?int $fingerprintVersion = null,
    ): array {
        $unbound = $boundExpiry === null;
        $expired = $boundExpiry === null || $boundExpiry->lessThanOrEqualTo($now);
        $remaining = null;
        if ($boundExpiry !== null && $deletedAt === null) {
            $remaining = $this->remainingDays($now, $boundExpiry);
        }

        return [
            'category' => $limit->category->value,
            'expires_at' => $boundExpiry,
            'expired' => $expired,
            'unbound' => $unbound,
            'remaining_days' => $remaining,
            'deleted_at' => $deletedAt,
            'fingerprint_key_id' => $deletedAt === null ? null : $fingerprintKeyId,
            'fingerprint_version' => $deletedAt === null ? null : $fingerprintVersion,
        ];
    }

    /** @param array<string, int|string> $overrides */
    private function pageUrl(array $overrides): string
    {
        $defaults = [
            'reviewerFilter' => '',
            'dispositionFilter' => '',
            'changedFilesPage' => 1,
            'findingsPage' => 1,
            'artifactsPage' => 1,
            'eventsPage' => 0,
        ];
        $current = [
            'reviewerFilter' => $this->reviewerFilter,
            'dispositionFilter' => $this->dispositionFilter,
            'changedFilesPage' => $this->changedFilesPage,
            'findingsPage' => $this->findingsPage,
            'artifactsPage' => $this->artifactsPage,
            'eventsPage' => $this->eventsPage,
            ...$overrides,
        ];
        $query = array_filter($current, static fn (int|string $value, string $key): bool => $value !== $defaults[$key], ARRAY_FILTER_USE_BOTH);

        return route('projects.runs.show', [$this->project, $this->runId]).($query === [] ? '' : '?'.http_build_query($query));
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
    private function readArtifactPayload(RunArtifactStore $store, RunArtifact $artifact): array
    {
        $bytes = $store->bytes($artifact);
        if ($bytes === null) {
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
