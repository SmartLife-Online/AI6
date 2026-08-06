<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class ControlOperationRecoveryProcessor
{
    public function __construct(
        private DeployKeyProvisioner $deployKeys,
    ) {}

    public function apply(ControlOperation $operation): void
    {
        $decision = $operation->recoveryDecision()->first();
        if (! $decision instanceof ControlOperationRecoveryDecision) {
            return;
        }
        if ($decision->expected_attempt_token !== $operation->recovery_attempt_token
            || $decision->expected_operation_version !== $operation->recovery_version
            || ! hash_equals($decision->finding_hash, (string) $operation->finding_hash)) {
            $decision->forceFill(['state' => 'rejected', 'applied_at' => Date::now()])->save();

            return;
        }

        try {
            match ($decision->decision) {
                RecoveryDecisionType::RETRY_RECONCILIATION => $this->deployKeys->retryRecovery($operation, $decision),
                RecoveryDecisionType::ADOPT_EXTERNAL_STATE => $this->deployKeys->adoptExternalState($operation, $decision),
                RecoveryDecisionType::ABANDON_OPERATION => $this->abandon($operation, $decision),
            };
        } catch (ControlOperationRetryableConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $decision->forceFill(['state' => 'rejected', 'applied_at' => Date::now()])->save();

            throw new RuntimeException('The recovery decision could not be applied safely.', previous: $exception);
        }
    }

    private function abandon(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        if (trim((string) $decision->reason) === '' || trim((string) $decision->bound_evidence) === '') {
            throw new RuntimeException('The abandonment decision is missing its required evidence.');
        }

        $attemptToken = $operation->current_attempt_token;
        if ($attemptToken === null) {
            throw new RuntimeException('The abandonment decision has no current attempt token.');
        }
        $this->deployKeys->cleanupFailedAttempt($operation, $attemptToken);

        DB::transaction(function () use ($operation, $decision, $attemptToken): void {
            $projectUpdated = Project::query()
                ->whereKey($operation->project_id)
                ->where('provisioning_operation_id', $operation->id)
                ->where('operation_lock_operation_id', $operation->id)
                ->where('operation_lock_attempt_token', $attemptToken)
                ->update([
                    'provisioning_status' => ProjectProvisioningStatus::PROVISIONING_FAILED,
                    'provisioning_operation_id' => null,
                ]);
            if ($projectUpdated !== 1) {
                throw new RuntimeException('The abandonment decision lost its project compare-and-swap binding.');
            }
            $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'abandoned');
            $result = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $operation->id],
                [
                    'outcome' => ControlOperationOutcome::ABANDONED,
                    'result_binding' => $binding,
                    'safe_summary' => 'The operation was abandoned by an authorized recovery decision.',
                ],
            );
            if ($result->outcome !== ControlOperationOutcome::ABANDONED
                || ! hash_equals($result->result_binding, $binding)) {
                throw new RuntimeException('The existing abandonment result has a different provenance binding.');
            }
            $updated = ControlOperation::query()
                ->whereKey($operation->id)
                ->where('current_attempt_token', $attemptToken)
                ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                ->where('version', $operation->version)
                ->where('finding_hash', $operation->finding_hash)
                ->update([
                    'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                    'state' => ControlOperationState::ABANDONED,
                    'recovery_attempt_token' => null,
                    'recovery_version' => null,
                    'recovery_effect_hash' => null,
                    'completed_at' => Date::now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => Date::now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The abandonment decision lost its compare-and-swap binding.');
            }

            $decisionUpdated = ControlOperationRecoveryDecision::query()
                ->whereKey($decision->id)
                ->where('state', 'pending')
                ->update([
                    'state' => 'applied',
                    'applied_at' => Date::now(),
                    'updated_at' => Date::now(),
                ]);
            if ($decisionUpdated !== 1) {
                throw new RuntimeException('The abandonment decision lost its single-consumption binding.');
            }
        });
    }
}
