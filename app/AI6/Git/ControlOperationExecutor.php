<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class ControlOperationExecutor
{
    public function __construct(
        private ProjectOperationLease $lease,
        private DeployKeyProvisioner $deployKeys,
        private ControlOperationConfiguration $configuration,
        private Redactor $redactor,
        private ControlOperationRecoveryProcessor $recovery,
        private ControlOperationRuntimeIdentity $runtimeIdentity,
    ) {}

    public function execute(string $operationId): void
    {
        $operation = ControlOperation::query()->findOrFail($operationId);
        if ($operation->state->terminal()) {
            if ($operation->current_attempt_token !== null) {
                $this->releaseTerminalLease(
                    $operation,
                    $operation->current_attempt_token,
                );
            }

            return;
        }

        if ($this->runtimeIdentity->runtimeRole !== 'worker') {
            throw new RuntimeException('Control operations may execute only in the worker role.');
        }

        $heartbeatDirectory = $this->runtimeIdentity->heartbeatDirectory;
        if ($heartbeatDirectory !== RuntimeHeartbeat::WORKER_DIRECTORY) {
            throw new RuntimeException('The worker heartbeat identity is unavailable.');
        }
        $bootId = (new RuntimeHeartbeat($heartbeatDirectory))->bootId();
        $recoveryInspection = $operation->state === ControlOperationState::RECOVERY_REQUIRED
            && ! $operation->recoveryDecision()->exists();
        $attemptToken = $recoveryInspection
            ? $this->lease->claimRecoveryInspection($operation)
            : $this->lease->claim($operation, $bootId);
        if ($attemptToken === null) {
            $this->recordClaimConflict($operation);

            return;
        }

        $operation->refresh();
        try {
            if ($operation->state === ControlOperationState::RECOVERY_REQUIRED) {
                if ($recoveryInspection) {
                    $this->requireRecovery(
                        $operation,
                        $attemptToken,
                        new ControlOperationRecoveryRequired(
                            $operation->last_error ?: 'Der persistierte Recoverybefund wurde erneut geprüft.',
                        ),
                    );

                    return;
                }

                try {
                    $this->recovery->apply($operation);
                } catch (ControlOperationRetryableConflict $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->requireRecovery($operation, $attemptToken, $exception, false);

                    return;
                }
                $operation->refresh();
                if ($operation->state->terminal()) {
                    $this->releaseTerminalLease($operation, $attemptToken);

                    return;
                }
                if ($operation->state === ControlOperationState::RECOVERY_REQUIRED) {
                    $this->requireRecovery(
                        $operation,
                        $attemptToken,
                        new ControlOperationRecoveryRequired('The recovery decision no longer matches the observed effect.'),
                        false,
                    );

                    return;
                }
            }

            for ($step = 0; $step < 8; $step++) {
                if (! $this->lease->heartbeat($operation->id, $operation->project_id, $attemptToken)) {
                    throw new RuntimeException('The control operation lost its lease heartbeat.');
                }
                if ($this->deployKeys->advance($operation, $attemptToken)) {
                    return;
                }
                $operation->refresh();
            }

            throw new RuntimeException('The operation exceeded its bounded state transitions.');
        } catch (ControlOperationRecoveryRequired $exception) {
            $this->requireRecovery($operation, $attemptToken, $exception);
        } catch (ControlOperationRetryableConflict $exception) {
            $this->recordRetryableConflict($operation, $attemptToken, $exception);
        } catch (Throwable $exception) {
            $this->recordFailure($operation, $attemptToken, $exception);
        }
    }

    private function requireRecovery(
        ControlOperation $operation,
        int $attemptToken,
        Throwable $exception,
        bool $preserveExistingDeviation = true,
    ): void {
        $operation->refresh();
        $deviation = $preserveExistingDeviation
            && $operation->state === ControlOperationState::RECOVERY_REQUIRED
            && $operation->last_error !== null
            ? $operation->last_error
            : $this->recoveryDeviation($operation, $exception);
        try {
            $finding = $this->deployKeys->recoveryFinding($operation, $attemptToken, $deviation);
        } catch (Throwable $inspectionFailure) {
            $this->recordRecoveryInspectionFailure($operation, $attemptToken, $inspectionFailure);

            return;
        }

        if ($operation->state === ControlOperationState::RECOVERY_REQUIRED
            && $operation->finding_hash !== null
            && hash_equals($operation->finding_hash, $finding['hash'])) {
            ControlOperation::query()
                ->whereKey($operation->id)
                ->where('current_attempt_token', $attemptToken)
                ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                ->where('finding_hash', $operation->finding_hash)
                ->update([
                    'last_error' => $deviation,
                    'updated_at' => Date::now(),
                ]);
            $this->lease->expire($operation->id, $operation->project_id, $attemptToken);

            return;
        }

        $updated = ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->where('version', $operation->version)
            ->whereIn('state', [
                ControlOperationState::RUNNING->value,
                ControlOperationState::RECOVERY_REQUIRED->value,
            ])
            ->update([
                'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
                'state' => ControlOperationState::RECOVERY_REQUIRED,
                'finding_text' => $finding['text'],
                'finding_hash' => $finding['hash'],
                'recovery_attempt_token' => $attemptToken,
                'recovery_version' => $operation->version + 1,
                'recovery_effect_hash' => $finding['effect_hash'],
                'last_error' => $deviation,
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);
        if ($updated === 1) {
            $this->lease->expire($operation->id, $operation->project_id, $attemptToken);
        }
    }

    private function recordClaimConflict(ControlOperation $operation): void
    {
        $safe = 'Project operation lease conflict.';
        $operation->refresh();
        if ($operation->state !== ControlOperationState::QUEUED) {
            // This attempt never obtained a claim, so it holds no attempt
            // token to bind a compare-and-swap update to. The operation is
            // RUNNING or RECOVERY_REQUIRED under some other attempt's lease;
            // only the owning attempt may mutate last_error or version for
            // it (see requireRecovery/recordFailure/recordRetryableConflict,
            // all of which CAS on current_attempt_token). A non-owner simply
            // has nothing to record here.
            return;
        }

        DB::transaction(function () use ($operation, $safe): void {
            $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'lease-conflict');
            $result = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $operation->id],
                [
                    'outcome' => ControlOperationOutcome::FAILED,
                    'result_binding' => $binding,
                    'safe_summary' => $safe,
                ],
            );
            if ($result->outcome !== ControlOperationOutcome::FAILED
                || ! hash_equals($result->result_binding, $binding)) {
                throw new RuntimeException('The existing lease-conflict result has a different provenance binding.');
            }

            $updated = ControlOperation::query()
                ->whereKey($operation->id)
                ->where('state', ControlOperationState::QUEUED)
                ->update([
                    'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                    'state' => ControlOperationState::FAILED,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => $safe,
                    'completed_at' => Date::now(),
                    'version' => DB::raw('version + 1'),
                    'updated_at' => Date::now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The lease conflict lost its terminal compare-and-swap binding.');
            }

            Project::query()
                ->whereKey($operation->project_id)
                ->where('provisioning_operation_id', $operation->id)
                ->update([
                    'provisioning_status' => ProjectProvisioningStatus::PROVISIONING_FAILED,
                    'provisioning_operation_id' => null,
                ]);
        });
    }

    private function recordRetryableConflict(
        ControlOperation $operation,
        int $attemptToken,
        ControlOperationRetryableConflict $exception,
    ): void {
        $operation->refresh();
        $safe = $this->safeMessage($operation, $exception);
        ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->whereIn('state', [
                ControlOperationState::RUNNING->value,
                ControlOperationState::RECOVERY_REQUIRED->value,
            ])
            ->update([
                'last_error' => $safe,
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);
        $this->lease->expire($operation->id, $operation->project_id, $attemptToken);

        throw new ControlOperationRetryableConflict(
            $exception->conflict,
            'The control operation encountered a named retryable conflict.',
        );
    }

    private function recordFailure(ControlOperation $operation, int $attemptToken, Throwable $exception): void
    {
        $operation->refresh();
        $safe = $this->safeMessage($operation, $exception);
        if ($operation->attempts >= $this->configuration->maxAttempts) {
            try {
                $active = $this->deployKeys->activeIntentUnderLock($operation, $attemptToken);
            } catch (Throwable $inspectionFailure) {
                $this->requireRecovery($operation, $attemptToken, $inspectionFailure);

                return;
            }

            if ($active !== null) {
                $this->requireRecovery($operation, $attemptToken, new ControlOperationRecoveryRequired(
                    'Retry exhaustion left an active deploy-key effect that requires a human decision.',
                ));

                return;
            }

            try {
                $this->deployKeys->cleanupFailedAttempt($operation, $attemptToken);
            } catch (Throwable $cleanupFailure) {
                $this->requireRecovery($operation, $attemptToken, $cleanupFailure);

                return;
            }

            DB::transaction(function () use ($operation, $attemptToken, $safe): void {
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
                    throw new RuntimeException('The failed operation lost its project compare-and-swap binding.');
                }
                $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'failed');
                $result = ControlOperationResult::query()->firstOrCreate(
                    ['control_operation_id' => $operation->id],
                    [
                        'outcome' => ControlOperationOutcome::FAILED,
                        'result_binding' => $binding,
                        'safe_summary' => $safe,
                    ],
                );
                if ($result->outcome !== ControlOperationOutcome::FAILED
                    || ! hash_equals($result->result_binding, $binding)) {
                    throw new RuntimeException('The existing failure result has a different provenance binding.');
                }
                $operationUpdated = ControlOperation::query()
                    ->whereKey($operation->id)
                    ->where('current_attempt_token', $attemptToken)
                    ->where('state', ControlOperationState::RUNNING)
                    ->update([
                        'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                        'state' => ControlOperationState::FAILED,
                        'last_error' => $safe,
                        'completed_at' => Date::now(),
                        'version' => DB::raw('version + 1'),
                        'updated_at' => Date::now(),
                    ]);
                if ($operationUpdated !== 1) {
                    throw new RuntimeException('The failed operation lost its terminal compare-and-swap binding.');
                }
            });
            $operation->refresh();
            $this->releaseTerminalLease($operation, $attemptToken);

            return;
        }

        ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->where('state', ControlOperationState::RUNNING)
            ->update([
                'last_error' => $safe,
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);
        $this->lease->expire($operation->id, $operation->project_id, $attemptToken);

        throw new RuntimeException('The control operation attempt failed and remains retryable.');
    }

    private function safeMessage(ControlOperation $operation, Throwable $exception): string
    {
        $message = $exception->getMessage() ?: 'The control operation failed without a diagnostic.';

        try {
            return $this->redactor->redact(
                $message,
                new RedactionContext((string) $operation->project_id, $operation->id, 'control-operation-failure'),
            )->text;
        } catch (InvalidRedactionInputException) {
            return 'The control operation produced an invalid diagnostic.';
        }
    }

    private function recoveryDeviation(ControlOperation $operation, Throwable $exception): string
    {
        $safe = $this->safeMessage($operation, $exception);

        return match ($safe) {
            'The active deploy-key path has an unsafe type.' => 'Der aktive Deploy-Key-Pfad besitzt einen unsicheren Typ.',
            'The deploy-key intent could not be read.' => 'Der persistierte Deploy-Key-Intent konnte nicht gelesen werden.',
            'The deploy-key intent is malformed.' => 'Der persistierte Deploy-Key-Intent ist syntaktisch ungültig.',
            'The deploy-key intent is incomplete.' => 'Der persistierte Deploy-Key-Intent ist unvollständig.',
            'The launch intent has no bound effect attempt.' => 'Der Launch-Intent besitzt keinen gebundenen Effektversuch.',
            'The persisted launch arguments no longer match this operation.' => 'Die persistierten Launch-Argumente stimmen nicht mehr mit der Operation überein.',
            'An active deploy key is not bound to this operation.' => 'Der aktive Deploy-Key ist nicht an diese Operation gebunden.',
            'The generated key has no bound effect attempt.' => 'Der erzeugte Deploy-Key besitzt keinen gebundenen Effektversuch.',
            'The active deploy key lost its operation binding before finalization.' => 'Der aktive Deploy-Key verlor vor der Finalisierung seine Operationsbindung.',
            'The provisioned project does not match the active deploy key.' => 'Der provisionierte Projektzustand stimmt nicht mit dem aktiven Deploy-Key überein.',
            'Project provenance changed before deploy-key finalization.' => 'Die Projektherkunft änderte sich vor der Deploy-Key-Finalisierung.',
            'Retry exhaustion left an active deploy-key effect that requires a human decision.' => 'Nach ausgeschöpften Wiederholungen verbleibt ein aktiver Deploy-Key-Außenstand, der eine menschliche Entscheidung erfordert.',
            'The active deploy key still differs from the operation intent.' => 'Der aktive Deploy-Key weicht weiterhin vom persistierten Operationsintent ab.',
            'The external deploy-key state changed after the recovery finding.' => 'Der externe Deploy-Key-Außenstand änderte sich nach dem Recoverybefund.',
            default => 'Die sichere Reconciliation konnte Außenstand und persistierten Intent nicht konsistent zusammenführen.',
        };
    }

    private function recordRecoveryInspectionFailure(
        ControlOperation $operation,
        int $attemptToken,
        Throwable $exception,
    ): void {
        $safe = $exception instanceof ControlOperationRetryableConflict
            ? $this->safeMessage($operation, $exception)
            : 'Der Recovery-Außenstand konnte nicht sicher erhoben werden; die Inspektion wird wiederholt.';
        ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->whereIn('state', [
                ControlOperationState::RUNNING->value,
                ControlOperationState::RECOVERY_REQUIRED->value,
            ])
            ->update([
                'last_error' => $safe,
                'updated_at' => Date::now(),
            ]);
        $this->lease->expire($operation->id, $operation->project_id, $attemptToken);
    }

    private function releaseTerminalLease(ControlOperation $operation, int $attemptToken): void
    {
        $this->lease->releaseTerminal($operation, $attemptToken);
    }
}
