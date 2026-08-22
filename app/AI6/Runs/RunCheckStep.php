<?php

namespace App\AI6\Runs;

use App\AI6\Checks\CheckerRuntimeConfiguration;
use App\AI6\Checks\CheckExecutionBoundaryReached;
use App\AI6\Checks\CheckExecutionRoleRequired;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\DuplicateCheckExecution;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Checks\OrphanedCheckExecution;
use App\AI6\Git\CanonicalDiff;
use App\AI6\Git\RunCheckpointConflict;
use App\AI6\Git\RunCheckpointService;
use App\AI6\Git\RunTreeService;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\ScopeApprovalService;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketV1Parser;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * The registered check-step handler.
 *
 * The profile set comes exclusively from the configuration snapshot bound to
 * the run, never from the current worktree content. Every execution goes
 * through the one CheckRunner; a failed check parks the run behind the single
 * check_failure producer instead of ending it.
 */
final readonly class RunCheckStep
{
    /** The phase this step runs. The final phase belongs to the finalisation flow (AI6-027). */
    private const PHASE = CheckPhase::BEFORE_REVIEW;

    public function __construct(
        private RunOrchestrator $orchestrator,
        private CheckRunner $checks,
        private CheckerRuntimeConfiguration $checkerRuntime,
        private RunCheckpointService $checkpoints,
        private RunTreeService $trees,
        private TicketV1Parser $tickets,
        private ScopeReconciliation $scopeReconciliation,
        private ScopeApprovalService $scopeApprovals,
        private EffectiveProjectConfiguration $projectConfiguration,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        $profiles = $this->boundProfiles($run);
        if ($profiles === null) {
            // A snapshot whose checks block is absent or malformed is never
            // read as "no checks required": the step fails closed (AC-09).
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der gebundene Konfigurations-Snapshot trägt keine gültige Checkliste.', 'check_snapshot_invalid');
            $this->orchestrator->failRun($run->id);

            return;
        }

        $intent = $this->boundIntent($run, $profiles, $job->intent === null ? $this->newExecutions($profiles) : null);
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                // The lease was taken over; the reconciler redelivers the step.
                return;
            }
            $job->intent = json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } else {
            $stored = $this->decodedIntent($job->intent);
            if ($stored === null || ! $this->intentMatches($stored, $this->boundIntent($run, $profiles))) {
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Schritt-Intent ist nicht gebunden.', 'invalid_step_intent');
                $this->orchestrator->failRun($run->id);

                return;
            }
            $intent = $stored;
        }

        $failed = [];
        foreach ($profiles as $profile) {
            try {
                if ($this->checks->mayExecuteHere()) {
                    $record = $this->checks->run($run, self::PHASE, $profile, $job->attempts);
                } else {
                    $executions = json_decode((string) ($intent['executions'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
                    $execution = is_array($executions) ? ($executions[$profile] ?? null) : null;
                    if (! is_array($execution) || ! is_string($execution['id'] ?? null) || ! is_int($execution['deadline_at'] ?? null)) {
                        throw new JsonException('The check execution intent is invalid.');
                    }
                    $record = $this->checks->dispatchOrCollect($run, self::PHASE, $profile, $execution['id'], $execution['deadline_at']);
                    if (! $record instanceof CheckResultRecord) {
                        $this->orchestrator->recordStepEvent(
                            $run->id, ExecutionStepType::CHECK->value, ExecutionJobState::WAITING,
                            'Checkergebnis ausstehend.', 'check_pending:'.$execution['id'],
                        );
                        $this->orchestrator->parkPollingStep($job, $owner);

                        return;
                    }
                }
            } catch (DuplicateCheckExecution $exception) {
                // The same execution already has a live result: neither a
                // second process nor a second row is produced. The lookup binds
                // the colliding key, so no foreign tree can contribute a
                // result, and explicitly the *live* row: after a retry on the
                // unchanged tree a superseded predecessor shares that very key
                // and is the older of the two.
                $record = $this->checks->liveResult($exception->resultKey);
                if (! $record instanceof CheckResultRecord) {
                    $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Das gebundene Checkergebnis ist nicht mehr auffindbar.', 'check_result_missing');
                    $this->orchestrator->failRun($run->id);

                    return;
                }
            } catch (CheckExecutionRoleRequired) {
                // The isolated checker role is the only place the check
                // promises of AGT-007, GIT-010 and SEC-005 hold. Refusing here
                // is fail closed: nothing ran, and nothing is reported as done.
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Checks laufen ausschließlich in der isolierten Checkerrolle.', 'check_execution_role_required');
                $this->orchestrator->failRun($run->id);

                return;
            } catch (CheckExecutionBoundaryReached $exception) {
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Checker-Ausführung erreichte eine terminale Grenze.', $exception->reason);
                $this->orchestrator->failRun($run->id);

                return;
            } catch (OrphanedCheckExecution $exception) {
                // A previous delivery of this very attempt ordered the check and
                // died before its result. Nothing ran, so the step must not
                // report anything as done; the named failure makes it visible.
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Eine frühere Zustellung dieses Checkversuchs hinterließ kein Ergebnis.', 'check_execution_orphaned');
                $this->orchestrator->failRun($run->id);

                return;
            } catch (CheckProfileConfigurationException $exception) {
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Checkprofil ist ungültig: '.$exception->reason.'.', $exception->reason);
                $this->orchestrator->failRun($run->id);

                return;
            } catch (Throwable) {
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Checkausführung ist fehlgeschlagen.', 'check_execution_failed');
                $this->orchestrator->failRun($run->id);

                return;
            }

            $this->orchestrator->recordStepEvent(
                $run->id,
                ExecutionStepType::CHECK->value,
                $record->state === CheckResultState::SUCCEEDED ? ExecutionJobState::SUCCEEDED : ExecutionJobState::FAILED,
                $this->resultEventMessage($profile, $record),
                'check:'.$record->result_key,
            );

            if ($record->state !== CheckResultState::SUCCEEDED) {
                $failed[] = $profile;
            }
        }

        if ($failed !== []) {
            $this->parkOnFailure($job, $run, $owner, $failed);

            return;
        }

        $this->finalizeReviewBoundary($job, $run->fresh() ?? $run, $owner);
    }

    private function finalizeReviewBoundary(ExecutionJob $job, Run $run, string $owner): void
    {
        if (config('ai6.runtime_role') !== 'worker') {
            // Unit/feature probes may exercise the explicitly reduced direct
            // checker path outside the worker. They prove the CheckRunner
            // contract, but they must neither create a Git checkpoint nor mark
            // the run review-ready. The production worker performs this bound
            // transition after collecting the same result.
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Checks abgeschlossen; Reviewbereitschaft erfordert die Workerrolle.');

            return;
        }

        try {
            $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
            $readModel = TicketReadModel::query()->where('project_id', $run->project_id)
                ->where('relative_path', $approval->relative_path)->firstOrFail();
            $this->orchestrator->prepareGates(
                $run,
                $this->tickets->parse($readModel->redacted_content),
                $readModel->ticket_contract_sha256,
            );

            $project = $run->project()->firstOrFail();
            if (! is_string($project->project_identifier) || $project->project_identifier === '') {
                throw new \RuntimeException('The run project identifier is unavailable.');
            }
            $context = new RedactionContext((string) $run->project_id, $run->id, 'review-readiness');
            $run = $this->checkpoints->create($run, $project->project_identifier, $context);
            $diff = $this->trees->workingTreeDiff($run, $run->initial_run_base_sha, $context);
            $decision = $this->orchestrator->reviewReadiness($run, $diff->hash, $this->checks->currentTreeBinding($run));
            $run = $this->orchestrator->recordReviewReadiness($run, $decision);
        } catch (RunCheckpointConflict $conflict) {
            $decision = new ReviewReadinessDecision([
                new ReviewBlocker($conflict->reason, 'Ein importierter Pfad fehlt im gebundenen Checkpoint.'),
            ], []);
            $run = $this->orchestrator->recordReviewReadiness($run, $decision);
            $this->orchestrator->finishStep(
                $job,
                $owner,
                ExecutionJobState::FAILED,
                'Der Checkpoint bindet nicht alle importierten Pfade.',
                $conflict->reason,
            );
            $this->orchestrator->failRun($run->id);

            return;
        } catch (RunTransitionConflict $conflict) {
            if ($conflict->reason === 'ticket_contract_drift') {
                $decision = new ReviewReadinessDecision([
                    new ReviewBlocker('ticket_contract_drift', 'Der freigegebene Ticketvertrag hat sich geändert.'),
                ], []);
                $run = $this->orchestrator->recordReviewReadiness($run, $decision);
                $this->orchestrator->recordStepEvent(
                    $run->id,
                    ExecutionStepType::CHECK->value,
                    ExecutionJobState::FAILED,
                    $decision->blockers[0]->message,
                    'review_blocker:ticket_contract_drift:'.$run->version,
                );
                $this->orchestrator->parkOnBaseDrift($run, $run->version);
                $this->orchestrator->parkStep($job, $owner);

                return;
            }
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Reviewbereitschaft konnte nicht gebunden werden.', $conflict->reason);
            $this->orchestrator->failRun($run->id);

            return;
        } catch (Throwable) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Reviewbereitschaft konnte nicht gebunden werden.', 'review_readiness_failed');
            $this->orchestrator->failRun($run->id);

            return;
        }

        if (! $decision->ready()) {
            foreach ($decision->blockers as $index => $blocker) {
                $this->orchestrator->recordStepEvent(
                    $run->id, ExecutionStepType::CHECK->value, ExecutionJobState::FAILED,
                    $blocker->message, 'review_blocker:'.$index.':'.$blocker->code.':'.$run->version,
                );
            }
            $drift = array_filter($decision->blockers, static fn (ReviewBlocker $blocker): bool => in_array(
                $blocker->code, ['ticket_contract_drift', 'control_head_drift'], true,
            ));
            if ($drift !== []) {
                $this->orchestrator->parkOnBaseDrift($run, $run->version);
                $this->orchestrator->parkStep($job, $owner);

                return;
            }
            $scope = array_filter(
                $decision->blockers,
                static fn (ReviewBlocker $blocker): bool => $blocker->code === 'scope_unresolved',
            );
            if ($scope !== []) {
                try {
                    $this->routeUnresolvedScope($job, $run, $owner, $diff);
                } catch (ImplementationImportException|RunTransitionConflict $conflict) {
                    $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Scope-Entscheidung konnte nicht abgeschlossen werden.', $conflict->reason);
                    $this->orchestrator->failRun($run->id);
                } catch (Throwable) {
                    $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Scope-Entscheidung konnte nicht abgeschlossen werden.', 'scope_reconciliation_failed');
                    $this->orchestrator->failRun($run->id);
                }

                return;
            }
            $checks = array_filter(
                $decision->blockers,
                static fn (ReviewBlocker $blocker): bool => $blocker->code === 'check_snapshot_invalid'
                    || str_starts_with($blocker->code, 'required_check_'),
            );
            if ($checks !== []) {
                $this->parkOnReadinessWait($job, $run, $owner, WaitReason::CHECK_FAILURE);

                return;
            }
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Reviewbereitschaft ist blockiert.', 'review_readiness_blocked');
            $this->orchestrator->failRun($run->id);

            return;
        }

        if (! $this->orchestrator->applyPreparedStepEffect($run, ExecutionStepType::CHECK)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Run ist nicht mehr ausführbar.', 'run_not_executable');

            return;
        }
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Checks und Reviewbereitschaft abgeschlossen.');
    }

    private function parkOnReadinessWait(ExecutionJob $job, Run $run, string $owner, WaitReason $reason): void
    {
        $fresh = Run::query()->findOrFail($run->getKey());
        try {
            $this->orchestrator->transition($fresh, $fresh->version, RunState::WAITING, RunPhase::CHECK, $reason);
        } catch (RunTransitionConflict) {
            // Another writer already moved the run; the step still has to
            // release its lease so the reconciler can resume it safely.
        }
        $this->orchestrator->parkStep($job, $owner);
    }

    private function routeUnresolvedScope(ExecutionJob $job, Run $run, string $owner, CanonicalDiff $diff): void
    {
        $snapshotId = ($run->config_snapshot ?? [])['snapshot_id'] ?? null;
        if ($snapshotId !== null && ! is_string($snapshotId)) {
            throw new RunTransitionConflict('check_snapshot_invalid', 'The bound configuration snapshot is invalid.');
        }

        $scope = $run->effective_scope_snapshot
            ?? array_values(array_filter(($run->scope_snapshot ?? [])['ticket_files'] ?? [], 'is_string'));
        $unresolved = $this->scopeReconciliation->unresolved($run->actual_changed_paths_snapshot ?? [], $scope);
        $statuses = [];
        foreach ($diff->entries as $entry) {
            $statuses[$entry['path']] = $entry['status'];
        }
        $configuration = $this->projectConfiguration
            ->bound($run->project()->firstOrFail(), $snapshotId)
            ->configuration;
        $slot = $this->orchestrator->ensureImplementationSlot($run);

        foreach ($unresolved as $path) {
            try {
                $request = $this->scopeApprovals->requestOrAutoAllow(
                    Run::query()->findOrFail($run->getKey()),
                    $configuration,
                    $path,
                    ($statuses[$path] ?? null) === 'D',
                    $slot->slot_id,
                    $job->idempotency_key,
                );
            } catch (HumanRequestRejected $rejected) {
                if ($rejected->reason === 'open_request_exists') {
                    $this->orchestrator->parkStep($job, $owner);

                    return;
                }
                $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Die Scope-Entscheidung konnte nicht angefordert werden.', $rejected->reason);
                $this->orchestrator->failRun($run->id);

                return;
            }

            if ($request !== null) {
                return;
            }
        }

        // Auto-Allow changes the evidence epoch. Replan with a fresh intent so
        // the checks execute under that new binding instead of reusing an old
        // mailbox order or result identity.
        $this->orchestrator->replanStepAfterEvidenceChange($job, $owner);
    }

    /**
     * Park the run behind the one check_failure producer.
     *
     * The step itself stays parked, so a resolver — retry on the unchanged
     * tree, a code fix through the orchestrator, or cancel — picks it up again.
     *
     * @param  list<string>  $failed
     */
    private function parkOnFailure(ExecutionJob $job, Run $run, string $owner, array $failed): void
    {
        $fresh = Run::query()->findOrFail($run->getKey());
        try {
            $this->orchestrator->transition($fresh, $fresh->version, RunState::WAITING, RunPhase::CHECK, WaitReason::CHECK_FAILURE);
        } catch (RunTransitionConflict) {
            // Another writer already moved the run; the step stays parked and
            // the reconciler redelivers it once the run is executable again.
        }

        $this->orchestrator->recordStepEvent(
            $run->id,
            ExecutionStepType::CHECK->value,
            ExecutionJobState::PLANNED,
            'Check fehlgeschlagen: '.implode(', ', $failed).'.',
            'check_failure:'.$job->idempotency_key.':'.$job->attempts,
        );
        $this->orchestrator->parkStep($job, $owner);
    }

    /**
     * The profile set of this run, taken from its bound configuration snapshot.
     *
     * Only a validly bound list is accepted — an empty one included, because a
     * project may legitimately require no check in this phase. An absent or
     * malformed checks block returns null and fails the step closed instead of
     * silently passing a run that was never checked.
     *
     * @return list<string>|null
     */
    private function boundProfiles(Run $run): ?array
    {
        // The approval snapshot nests the frozen project configuration under
        // `values` (ApprovalSnapshotFactory); the current worktree is never read.
        $snapshot = $run->config_snapshot;
        if (! is_array($snapshot) || ! is_array($snapshot['values']['checks'] ?? null)) {
            return null;
        }

        $configured = $snapshot['values']['checks'][self::PHASE->value] ?? null;
        if (! is_array($configured) || ! array_is_list($configured)) {
            return null;
        }

        $profiles = [];
        foreach ($configured as $profile) {
            if (! is_string($profile) || $profile === '') {
                return null;
            }
            $profiles[] = $profile;
        }

        return array_values(array_unique($profiles));
    }

    /**
     * @param  list<string>  $profiles
     * @param  null|array<string, array{id: string, deadline_at: int}>  $executions
     * @return array<string, scalar>
     */
    private function boundIntent(Run $run, array $profiles, ?array $executions = null): array
    {
        $intent = [
            'effect' => 'run_check_phase',
            'run_id' => $run->id,
            'phase' => self::PHASE->value,
            'profiles' => implode(',', $profiles),
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::CHECK, 1),
        ];
        if ($executions !== null) {
            $intent['executions'] = json_encode($executions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, scalar>  $expected
     */
    private function intentMatches(array $stored, array $expected): bool
    {
        $executions = $stored['executions'] ?? null;
        unset($stored['executions']);

        return is_string($executions) && $stored === $expected;
    }

    /** @return array<string, mixed>|null */
    private function decodedIntent(string $intent): ?array
    {
        try {
            $decoded = json_decode($intent, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $profiles
     * @return array<string, array{id: string, deadline_at: int}>
     */
    private function newExecutions(array $profiles): array
    {
        $seconds = $this->checkerRuntime->executionDeadlineSeconds;
        $executions = [];
        foreach ($profiles as $profile) {
            $executions[$profile] = ['id' => (string) Str::uuid(), 'deadline_at' => time() + $seconds];
        }

        return $executions;
    }

    private function resultEventMessage(string $profile, CheckResultRecord $record): string
    {
        $executionId = $record->getAttribute('checker_execution_id');
        $bootId = $record->getAttribute('checker_boot_id');
        $deadline = $record->getAttribute('checker_deadline_at');
        if (is_string($executionId) && is_string($bootId) && is_int($deadline)) {
            return 'Check '.$profile.': '.$record->state->value
                .'; Ausführung '.$executionId.', Checker-Boot '.$bootId.', Frist '.$deadline.'.';
        }

        return 'Check '.$profile.': '.$record->state->value.'.';
    }
}
