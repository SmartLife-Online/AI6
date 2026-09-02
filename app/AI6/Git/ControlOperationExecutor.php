<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Runs\QueueReevaluationTrigger;
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
        private ManagedCloneSynchronizer $managedClones,
        private ControlBranchChanger $controlBranches,
        private TicketReadModelRefresher $ticketReadModels,
        private ProjectConfigRefresher $projectConfigs,
        private TicketMutationExecutor $ticketMutations,
        private ControlOperationConfiguration $configuration,
        private Redactor $redactor,
        private ControlOperationRecoveryProcessor $recovery,
        private ControlOperationRuntimeIdentity $runtimeIdentity,
        private QueueReevaluation $queueReevaluation,
    ) {}

    public function execute(string $operationId): void
    {
        $operation = ControlOperation::query()->findOrFail($operationId);
        if ($operation->state->terminal()) {
            if ($operation->current_attempt_token !== null) {
                if (in_array($operation->operation_type, [
                    ControlOperationType::TICKET_EDIT,
                    ControlOperationType::TICKET_STATUS_CHANGE,
                    ControlOperationType::TICKET_APPROVAL,
                    ControlOperationType::RUN_START,
                    ControlOperationType::CONTRACT_AMENDMENT,
                ], true)) {
                    $this->ticketMutations->cleanupFailedAttempt(
                        $operation,
                        $operation->current_attempt_token,
                    );
                }
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
                $completed = match ($operation->operation_type) {
                    ControlOperationType::DEPLOY_KEY_PROVISION => $this->deployKeys->advance($operation, $attemptToken),
                    ControlOperationType::MANAGED_CLONE,
                    ControlOperationType::MANAGED_FETCH => $this->managedClones->advance($operation, $attemptToken),
                    ControlOperationType::CONTROL_BRANCH_CHANGE => $this->controlBranches->advance($operation, $attemptToken),
                    ControlOperationType::TICKET_REFRESH => $this->ticketReadModels->advance($operation, $attemptToken),
                    ControlOperationType::CONFIG_REFRESH => $this->projectConfigs->advance($operation, $attemptToken),
                    ControlOperationType::TICKET_EDIT,
                    ControlOperationType::TICKET_STATUS_CHANGE,
                    ControlOperationType::TICKET_APPROVAL => $this->ticketMutations->advance($operation, $attemptToken),
                    ControlOperationType::RUN_START,
                    ControlOperationType::CONTRACT_AMENDMENT => $this->ticketMutations->advance($operation, $attemptToken),
                };
                if ($completed) {
                    $this->queueReevaluation->afterExternalEffect(
                        $operation->project_id,
                        $this->reevaluationTrigger($operation->operation_type),
                    );

                    return;
                }
                $operation->refresh();
            }

            throw new RuntimeException('The operation exceeded its bounded state transitions.');
        } catch (TicketMutationGitConflict $exception) {
            $this->recordTerminalConflict(
                $operation,
                $attemptToken,
                new ControlOperationTerminalConflict($exception->conflict, $exception->getMessage()),
            );
            $this->queueReevaluation->afterExternalEffect(
                $operation->project_id,
                $this->reevaluationTrigger($operation->operation_type),
            );
        } catch (ControlOperationTerminalConflict $exception) {
            $this->recordTerminalConflict($operation, $attemptToken, $exception);
            $this->queueReevaluation->afterExternalEffect(
                $operation->project_id,
                $this->reevaluationTrigger($operation->operation_type),
            );
        } catch (ControlOperationRecoveryRequired $exception) {
            $this->requireRecovery($operation, $attemptToken, $exception);
        } catch (ControlOperationRetryableConflict $exception) {
            $this->recordRetryableConflict($operation, $attemptToken, $exception);
        } catch (Throwable $exception) {
            $this->recordFailure($operation, $attemptToken, $exception);
        }
    }

    private function reevaluationTrigger(ControlOperationType $type): QueueReevaluationTrigger
    {
        return match ($type) {
            ControlOperationType::MANAGED_FETCH => QueueReevaluationTrigger::FETCH,
            ControlOperationType::TICKET_REFRESH => QueueReevaluationTrigger::READ_MODEL_REFRESH,
            ControlOperationType::CONFIG_REFRESH => QueueReevaluationTrigger::CONFIG_CHANGE,
            ControlOperationType::TICKET_STATUS_CHANGE => QueueReevaluationTrigger::DEPENDENCY_STATUS_CHANGE,
            default => QueueReevaluationTrigger::TICKET_CHANGE,
        };
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
            $finding = match ($operation->operation_type) {
                ControlOperationType::DEPLOY_KEY_PROVISION => $this->deployKeys->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::MANAGED_CLONE,
                ControlOperationType::MANAGED_FETCH => $this->managedClones->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::CONTROL_BRANCH_CHANGE => $this->controlBranches->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::TICKET_REFRESH => $this->ticketReadModels->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::CONFIG_REFRESH => $this->projectConfigs->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::TICKET_EDIT,
                ControlOperationType::TICKET_STATUS_CHANGE,
                ControlOperationType::TICKET_APPROVAL => $this->ticketMutations->recoveryFinding($operation, $attemptToken, $deviation),
                ControlOperationType::RUN_START,
                ControlOperationType::CONTRACT_AMENDMENT => $this->ticketMutations->recoveryFinding($operation, $attemptToken, $deviation),
            };
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
        $safe = 'Die Operation steht in Konflikt mit der aktiven Projektsperre.';
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
        $safe = $this->persistedFailureSummary($operation, $exception);
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

    private function recordTerminalConflict(
        ControlOperation $operation,
        int $attemptToken,
        ControlOperationTerminalConflict $exception,
    ): void {
        if (in_array($operation->operation_type, [
            ControlOperationType::MANAGED_CLONE,
            ControlOperationType::MANAGED_FETCH,
        ], true)) {
            try {
                $this->managedClones->cleanupFailedAttempt($operation, $attemptToken);
            } catch (Throwable $cleanupFailure) {
                $this->requireRecovery($operation, $attemptToken, $cleanupFailure);

                return;
            }
        } elseif (in_array($operation->operation_type, [
            ControlOperationType::TICKET_EDIT,
            ControlOperationType::TICKET_STATUS_CHANGE,
            ControlOperationType::TICKET_APPROVAL,
            ControlOperationType::RUN_START,
            ControlOperationType::CONTRACT_AMENDMENT,
        ], true)) {
            try {
                $this->ticketMutations->cleanupFailedAttempt($operation, $attemptToken);
            } catch (Throwable $cleanupFailure) {
                $this->requireRecovery($operation, $attemptToken, $cleanupFailure);

                return;
            }
        }

        $safe = $this->safeMessage($operation, $exception);
        DB::transaction(function () use ($operation, $attemptToken, $exception, $safe): void {
            $this->ticketMutations->cancelApprovalEffect($operation);
            $binding = hash(
                'sha256',
                "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.$exception->conflict,
            );
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
                throw new RuntimeException('The existing terminal-conflict result has a different provenance binding.');
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
                throw new RuntimeException('The terminal conflict lost its operation compare-and-swap binding.');
            }

        });
        $this->ticketMutations->parkAmendedRunOnConflict($operation);
        $operation->refresh();
        $this->releaseTerminalLease($operation, $attemptToken);
    }

    private function recordFailure(ControlOperation $operation, int $attemptToken, Throwable $exception): void
    {
        $operation->refresh();
        $safe = $this->persistedFailureSummary($operation, $exception);
        if ($operation->attempts >= $this->configuration->maxAttempts) {
            try {
                $active = match ($operation->operation_type) {
                    ControlOperationType::DEPLOY_KEY_PROVISION => $this->deployKeys->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::MANAGED_CLONE,
                    ControlOperationType::MANAGED_FETCH => $this->managedClones->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::CONTROL_BRANCH_CHANGE => $this->controlBranches->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::TICKET_REFRESH => $this->ticketReadModels->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::CONFIG_REFRESH => $this->projectConfigs->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::TICKET_EDIT,
                    ControlOperationType::TICKET_STATUS_CHANGE,
                    ControlOperationType::TICKET_APPROVAL => $this->ticketMutations->activeIntentUnderLock($operation, $attemptToken),
                    ControlOperationType::RUN_START,
                    ControlOperationType::CONTRACT_AMENDMENT => $this->ticketMutations->activeIntentUnderLock($operation, $attemptToken),
                };
            } catch (Throwable $inspectionFailure) {
                $this->requireRecovery($operation, $attemptToken, $inspectionFailure);

                return;
            }

            if ($active !== null) {
                $this->requireRecovery($operation, $attemptToken, new ControlOperationRecoveryRequired(
                    'Retry exhaustion left an active external effect that requires a human decision.',
                ));

                return;
            }

            try {
                if ($operation->operation_type === ControlOperationType::DEPLOY_KEY_PROVISION) {
                    $this->deployKeys->cleanupFailedAttempt($operation, $attemptToken);
                } elseif (in_array($operation->operation_type, [
                    ControlOperationType::MANAGED_CLONE,
                    ControlOperationType::MANAGED_FETCH,
                ], true)) {
                    $this->managedClones->cleanupFailedAttempt($operation, $attemptToken);
                } elseif ($operation->operation_type === ControlOperationType::CONTROL_BRANCH_CHANGE) {
                    $this->controlBranches->cleanupFailedAttempt($operation, $attemptToken);
                } elseif (in_array($operation->operation_type, [
                    ControlOperationType::TICKET_EDIT,
                    ControlOperationType::TICKET_STATUS_CHANGE,
                    ControlOperationType::TICKET_APPROVAL,
                    ControlOperationType::RUN_START,
                    ControlOperationType::CONTRACT_AMENDMENT,
                ], true)) {
                    $this->ticketMutations->cleanupFailedAttempt($operation, $attemptToken);
                } elseif ($operation->operation_type === ControlOperationType::CONFIG_REFRESH) {
                    $this->projectConfigs->cleanupFailedAttempt($operation, $attemptToken);
                } else {
                    $this->ticketReadModels->cleanupFailedAttempt($operation, $attemptToken);
                }
            } catch (Throwable $cleanupFailure) {
                $this->requireRecovery($operation, $attemptToken, $cleanupFailure);

                return;
            }

            DB::transaction(function () use ($operation, $attemptToken, $safe): void {
                $this->ticketMutations->cancelApprovalEffect($operation);
                if ($operation->operation_type === ControlOperationType::DEPLOY_KEY_PROVISION) {
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

    private function persistedFailureSummary(ControlOperation $operation, Throwable $exception): string
    {
        if (! $exception instanceof ControlOperationRetryableConflict) {
            return $this->safeMessage($operation, $exception);
        }

        return match (true) {
            $exception->conflict === 'lease_lost' => ControlOperationPublicFailureText::LEASE_LOST,
            $exception->conflict === 'fencing_conflict' => ControlOperationPublicFailureText::FENCING_CONFLICT,
            str_starts_with($exception->conflict, 'effect_lock_') => ControlOperationPublicFailureText::EFFECT_LOCK_UNAVAILABLE,
            default => $this->safeMessage($operation, $exception),
        };
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
            'The managed Git launch has no durable effect intent.' => 'Der Managed-Git-Start besitzt keinen dauerhaft gebundenen Effekt-Intent.',
            'The persisted managed Git launch arguments no longer match this operation.' => 'Die persistierten Managed-Git-Startargumente stimmen nicht mehr mit der Operation überein.',
            'The staged managed-clone effect has no bound attempt token.' => 'Der vorbereitete Managed-Clone-Effekt besitzt keinen gebundenen Versuchs-Token.',
            'The control-binding version changed while this operation still owned the project lease.' => 'Die Control-Bindungsversion änderte sich, obwohl die Operation noch die Projektsperre hielt.',
            'The managed repository contains a ref outside the configured allowlist.' => 'Das verwaltete Repository enthält einen Ref außerhalb der konfigurierten Allowlist.',
            'The managed repository and active control binding are inconsistent.' => 'Das verwaltete Repository und die aktive Control-Bindung sind inkonsistent.',
            'The managed recovery binding compare-and-swap exhausted its configured budget.' => 'Der Recovery-Abgleich der Control-Bindung hat sein konfiguriertes Wiederholungsbudget ausgeschöpft.',
            'An inconsistent published managed-clone effect cannot be abandoned.' => 'Ein inkonsistenter veröffentlichter Managed-Clone-Effekt kann nicht sicher abgebrochen werden.',
            'The published managed-clone ref differs from the persisted intent.' => 'Der veröffentlichte Managed-Clone-Ref weicht vom persistierten Intent ab.',
            'The managed-clone operation has no valid persisted target OID.' => 'Die Managed-Clone-Operation besitzt keine gültige persistierte Ziel-OID.',
            'The managed-clone effect changed after the recovery finding.' => 'Der Managed-Clone-Effekt änderte sich nach dem Recoverybefund.',
            'The managed Git ref could not be resolved safely.' => 'Der verwaltete Git-Ref konnte nicht sicher aufgelöst werden.',
            'A published ticket mutation commit cannot be abandoned without rewriting control history.' => 'Ein veröffentlichter Ticketmutationscommit kann nicht abgebrochen werden, ohne die Control-Historie umzuschreiben; der gebundene Außenstand kann übernommen werden.',
            default => 'Die sichere Reconciliation konnte Außenstand und persistierten Intent nicht konsistent zusammenführen.',
        };
    }

    private function recordRecoveryInspectionFailure(
        ControlOperation $operation,
        int $attemptToken,
        Throwable $exception,
    ): void {
        $safe = $exception instanceof ControlOperationRetryableConflict
            ? $this->persistedFailureSummary($operation, $exception)
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
