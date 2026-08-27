<?php

namespace App\AI6\Runs;

use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class ReviewOnlyRunCoordinator
{
    public function __construct(
        private CompletionReportService $reports,
        private RunWorkspaceLifecycle $workspaces,
        private ReportOnlyCompletionService $completion,
        private RunOrchestrator $orchestrator,
        private RunLimitPolicy $limits,
        private HumanRequestService $humanRequests,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        $run->refresh();
        if ($run->run_type !== RunType::REVIEW_ONLY) {
            $this->finish($job, $owner, ExecutionJobState::FAILED, 'review_report_invalid');

            return;
        }
        $intent = [
            'effect' => 'publish_review_report',
            'run_id' => $run->id,
            'checkpoint_tree_sha' => (string) $run->checkpoint_tree_sha,
            'diff_hash' => (string) $run->checkpoint_diff_hash,
        ];
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                return;
            }
        } elseif ($this->decodeIntent($job->intent) !== $intent) {
            $this->finish($job, $owner, ExecutionJobState::FAILED, 'invalid_step_intent');

            return;
        }
        $report = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::COMPLETION_REPORT->value)->first();
        if (! $report instanceof RunArtifact) {
            $prepared = $this->reports->prepare($run);
            $limit = $this->limits->evaluate($run, [], [['bytes' => strlen($prepared['bytes'])]], 0);
            if ($limit instanceof ImportLimitResult) {
                try {
                    $this->humanRequests->openLimitRequest($run, $job, $limit, WaitReason::RESOURCE_LIMIT);
                } catch (HumanRequestRejected $exception) {
                    // A limit that can neither park nor be answered must end the
                    // step visibly instead of retrying until the budget is gone.
                    $this->finish($job, $owner, ExecutionJobState::FAILED, $exception->reason);
                }

                return;
            }
            $this->reports->persist($run, $prepared);
        }
        $project = Project::query()->findOrFail($run->project_id);
        if (! is_string($project->project_identifier) || $project->project_identifier === '') {
            $this->finish($job, $owner, ExecutionJobState::FAILED, 'managed_project_missing');

            return;
        }
        $this->workspaces->cleanupReviewOnly(
            $run,
            $project->project_identifier,
            new RedactionContext((string) $run->project_id, $run->id, 'review-workspace-cleanup'),
        );
        $fresh = $run->fresh() ?? $run;
        if ($fresh->pending_status_operation_id === null && $fresh->state !== RunState::COMPLETED
            && ! ($fresh->state === RunState::WAITING && $fresh->wait_reason === WaitReason::MANUAL_REPORT)) {
            try {
                $this->completion->start($fresh);
            } catch (RunTransitionConflict|HumanRequestRejected $refusal) {
                $this->blockCompletion($job, $fresh, $owner, $refusal);

                return;
            }
        }
        $this->finish($job, $owner, ExecutionJobState::SUCCEEDED);
    }

    /**
     * End a report step whose completion saga refuses to start.
     *
     * Both typed refusal families end here, because both are legitimate end
     * states of a review-only run (plan §20, `RUN-010`) rather than transport
     * errors: `RunTransitionConflict` carries the unsatisfied completion
     * predicate — an open manual or external gate, an incomplete criterion
     * coverage — and `HumanRequestRejected` carries a confirmation that cannot
     * be opened at all, above all an approval without an active attention user,
     * which no retry ever resolves.
     *
     * Parking instead would need a wait status whose resolver does not exist —
     * `manual_gate` has neither producer nor resolver — so the step ends
     * visibly with its named reason, exactly as the unparkable limit above and
     * `RunCheckStep` do for a blocked review readiness. The bound report stays
     * published; only the completion saga is refused.
     */
    private function blockCompletion(
        ExecutionJob $job,
        Run $run,
        string $owner,
        RunTransitionConflict|HumanRequestRejected $conflict,
    ): void {
        $this->orchestrator->recordStepEvent(
            $run->id,
            ExecutionStepType::REPORT->value,
            ExecutionJobState::FAILED,
            'Der report-only Abschluss ist blockiert: '.$conflict->getMessage().'.',
            $conflict->reason.':'.$run->id.':'.$run->version,
        );
        $this->finish($job, $owner, ExecutionJobState::FAILED, $conflict->reason);
        $this->orchestrator->failRun($run->id);
    }

    private function finish(ExecutionJob $job, string $owner, ExecutionJobState $state, ?string $failure = null): void
    {
        $this->orchestrator->finishStep(
            $job,
            $owner,
            $state,
            $state === ExecutionJobState::SUCCEEDED ? 'Abschlussbericht gebunden und Workspace bereinigt.' : 'Abschlussbericht fehlgeschlagen.',
            $failure,
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeIntent(string $intent): ?array
    {
        try {
            $decoded = json_decode($intent, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
