<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

final readonly class ControlOperationRecoveryProcessor
{
    public function __construct(
        private DeployKeyProvisioner $deployKeys,
        private ManagedCloneSynchronizer $managedClones,
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
            if (in_array($operation->operation_type, [
                ControlOperationType::CONTROL_BRANCH_CHANGE,
                ControlOperationType::TICKET_REFRESH,
            ], true)) {
                throw new RuntimeException('This control operation has no recoverable external effect.');
            }
            $handler = $operation->operation_type === ControlOperationType::DEPLOY_KEY_PROVISION
                ? $this->deployKeys
                : $this->managedClones;
            match ($decision->decision) {
                RecoveryDecisionType::RETRY_RECONCILIATION => $handler->retryRecovery($operation, $decision),
                RecoveryDecisionType::ADOPT_EXTERNAL_STATE => $handler->adoptExternalState($operation, $decision),
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

        if ($operation->operation_type === ControlOperationType::DEPLOY_KEY_PROVISION) {
            $this->deployKeys->abandon($operation, $decision);

            return;
        }

        if (in_array($operation->operation_type, [
            ControlOperationType::CONTROL_BRANCH_CHANGE,
            ControlOperationType::TICKET_REFRESH,
        ], true)) {
            throw new RuntimeException('This control operation cannot be abandoned through effect recovery.');
        }

        $this->managedClones->abandon($operation, $decision);
    }
}
