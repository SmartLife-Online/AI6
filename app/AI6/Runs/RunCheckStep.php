<?php

namespace App\AI6\Runs;

use App\AI6\Checks\CheckExecutionRoleRequired;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\DuplicateCheckExecution;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Checks\OrphanedCheckExecution;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
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

        $intent = $this->boundIntent($run, $profiles);
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                // The lease was taken over; the reconciler redelivers the step.
                return;
            }
        } elseif (! $this->intentMatches($job->intent, $intent)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Schritt-Intent ist nicht gebunden.', 'invalid_step_intent');
            $this->orchestrator->failRun($run->id);

            return;
        }

        $failed = [];
        foreach ($profiles as $profile) {
            try {
                $record = $this->checks->run($run, self::PHASE, $profile, $job->attempts);
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
                'Check '.$profile.': '.$record->state->value.'.',
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

        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Checks abgeschlossen.');
        $this->orchestrator->applyPreparedStepEffect($run->fresh() ?? $run, ExecutionStepType::CHECK);
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
     * @return array<string, scalar>
     */
    private function boundIntent(Run $run, array $profiles): array
    {
        return [
            'effect' => 'run_check_phase',
            'run_id' => $run->id,
            'phase' => self::PHASE->value,
            'profiles' => implode(',', $profiles),
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::CHECK, 1),
        ];
    }

    /** @param  array<string, scalar>  $expected */
    private function intentMatches(string $stored, array $expected): bool
    {
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return $decoded === $expected;
    }
}
