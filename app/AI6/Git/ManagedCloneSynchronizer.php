<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\PendingControlBinding;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\BlockedStartOutcome;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\EffectLockHandle;
use App\AI6\Shared\Process\EffectLockOutcome;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class ManagedCloneSynchronizer
{
    public function __construct(
        private ControlOperationConfiguration $configuration,
        private ManagedProjectPath $paths,
        private ProjectOperationLease $lease,
        private ProjectEffectLockName $lockNames,
        private HardenedGitRunner $git,
        private ControlRemoteProbe $remoteProbe,
        private EffectLock $effectLock,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
        private CanonicalJson $canonicalJson,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $operation->refresh();
        $this->assertType($operation);

        return match ($operation->phase) {
            ControlOperationPhase::CLAIMED => $this->stage($operation, $attemptToken, ControlOperationPhase::CLAIMED),
            ControlOperationPhase::LAUNCH_INTENT,
            ControlOperationPhase::PROCESS_STARTED => $this->reconcileStage($operation, $attemptToken),
            ControlOperationPhase::EFFECT_STAGED => $this->publishOutcome($operation, $attemptToken),
            ControlOperationPhase::OUTCOME_PUBLISHED => $this->finalizeBinding($operation, $attemptToken),
            ControlOperationPhase::BINDING_FINALIZED => $this->complete($operation, $attemptToken),
            ControlOperationPhase::ATTEMPT_COMPLETED => true,
            ControlOperationPhase::RECOVERY_REQUIRED => throw new ControlOperationRecoveryRequired('The managed-clone operation requires a human recovery decision.'),
            default => throw new RuntimeException('The managed-clone operation has an incompatible phase.'),
        };
    }

    private function stage(
        ControlOperation $operation,
        int $attemptToken,
        ControlOperationPhase $fromPhase,
    ): bool {
        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertExecutionContract($operation, $project, $parameters);
        if (! $this->projectPolicy->synchronizeManagedClone($actor, $project)
            || ! $this->authorizationSnapshots->matchesCurrent($operation, $actor, $project)) {
            throw new RuntimeException('Authorization changed before the managed Git process started.');
        }

        $targetOid = $this->remoteProbe->resolve(
            $project,
            $parameters['control_ref'],
            $this->context($operation, 'managed-remote-probe'),
        );
        if ($parameters['pending_control_oid'] !== null
            && ! hash_equals($parameters['pending_control_oid'], $targetOid)) {
            throw new ControlOperationTerminalConflict(
                'pending_control_oid_mismatch',
                'Der Remote-Control-Head stimmt nicht mit der ausstehenden Bindung überein.',
            );
        }

        $attemptRef = ManagedProjectPath::attemptRef($operation->id, $attemptToken);
        if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
            $staged = $this->paths->stagedRepository((string) $project->project_identifier, $operation->id, $attemptToken);
            $workingDirectory = dirname($staged);
            $argumentHash = $this->git->managedCloneArgumentHash(
                (string) $project->remote,
                $parameters['control_ref'],
                basename($staged),
                $workingDirectory,
                (string) $project->deploy_key_reference,
                $this->configuration->knownHostsFile,
                (string) $project->host_key_fingerprint,
                $this->context($operation, 'managed-clone'),
            );
        } else {
            $repository = $this->paths->assertRepository(
                $this->paths->repositoryDirectory((string) $project->project_identifier),
            );
            $argumentHash = $this->git->fetchArgumentHash(
                (string) $project->remote,
                $parameters['control_ref'],
                $attemptRef,
                $repository,
                (string) $project->deploy_key_reference,
                $this->configuration->knownHostsFile,
                (string) $project->host_key_fingerprint,
                $this->context($operation, 'managed-fetch'),
            );
        }

        $this->progress($operation, $attemptToken, $fromPhase, [
            'phase' => ControlOperationPhase::LAUNCH_INTENT,
            'effect_attempt_token' => $attemptToken,
            'target_control_oid' => $targetOid,
            'launch_argument_hash' => $argumentHash,
            'process_id' => null,
            'process_started_at' => null,
        ]);
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The operation lease was lost before managed Git process start.');
        }

        $lockName = $this->lockNames->forProject((string) $project->project_identifier);
        if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
            $stagedRepository = $this->paths->stagedRepository(
                (string) $project->project_identifier,
                $operation->id,
                $attemptToken,
            );
            $start = $this->git->startManagedClone(
                (string) $project->remote,
                $parameters['control_ref'],
                basename($stagedRepository),
                dirname($stagedRepository),
                (string) $project->deploy_key_reference,
                $this->configuration->knownHostsFile,
                (string) $project->host_key_fingerprint,
                $this->context($operation, 'managed-clone'),
                $lockName,
            );
        } else {
            $activeRepository = $this->paths->assertRepository(
                $this->paths->repositoryDirectory((string) $project->project_identifier),
            );
            $start = $this->git->startFetchToAttemptRef(
                (string) $project->remote,
                $parameters['control_ref'],
                $attemptRef,
                $activeRepository,
                (string) $project->deploy_key_reference,
                $this->configuration->knownHostsFile,
                (string) $project->host_key_fingerprint,
                $this->context($operation, 'managed-fetch'),
                $lockName,
            );
        }
        if (! $start->ready() || $start->process === null) {
            if (in_array($start->outcome, [
                BlockedStartOutcome::LOCK_UNAVAILABLE,
                BlockedStartOutcome::LOCK_CONFLICT,
                BlockedStartOutcome::CONFIGURATION_ERROR,
            ], true)) {
                throw new ControlOperationRetryableConflict(
                    'effect_lock_'.$start->outcome->value,
                    'Managed Git execution encountered a named effect-lock conflict: '.$start->message,
                );
            }

            throw new RuntimeException($start->message);
        }

        $blocked = $start->process;
        try {
            $this->progress($operation, $attemptToken, ControlOperationPhase::LAUNCH_INTENT, [
                'phase' => ControlOperationPhase::PROCESS_STARTED,
                'process_id' => $blocked->processId,
                'process_started_at' => Date::createFromTimestamp((int) $blocked->startedAt),
            ]);
            $blocked->release();
            $result = $blocked->wait(function () use ($operation, $attemptToken): void {
                if (! $this->lease->heartbeat($operation->id, $operation->project_id, $attemptToken)) {
                    throw new ControlOperationRetryableConflict('lease_lost', 'The operation lease was lost while managed Git was running.');
                }
            }, $this->configuration->heartbeatSeconds);
        } catch (\Throwable $exception) {
            $blocked->cancel();

            throw $exception;
        }
        if (! $result->succeeded()) {
            throw new RuntimeException($result->errorOutput !== '' ? $result->errorOutput : 'Managed Git execution failed.');
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The operation lease was lost before staged-effect validation.');
        }

        $this->assertStagedEffect(
            $operation,
            $project,
            $attemptToken,
            $parameters['control_ref'],
            $targetOid,
            $parameters['pending_control_oid'] !== null,
        );
        $this->progress($operation, $attemptToken, ControlOperationPhase::PROCESS_STARTED, [
            'phase' => ControlOperationPhase::EFFECT_STAGED,
        ]);

        return false;
    }

    private function reconcileStage(ControlOperation $operation, int $attemptToken): bool
    {
        $effectAttemptToken = $operation->effect_attempt_token;
        if ($effectAttemptToken === null || $operation->target_control_oid === null) {
            throw new ControlOperationRecoveryRequired('The managed Git launch has no durable effect intent.');
        }
        $project = $operation->project()->firstOrFail();
        if ($effectAttemptToken !== $attemptToken) {
            $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'superseded managed-attempt cleanup');
            try {
                $this->paths->removeOwnedAttempt(
                    (string) $project->project_identifier,
                    $operation->id,
                    $effectAttemptToken,
                );
            } finally {
                $lock->release();
            }

            return $this->stage($operation, $attemptToken, $operation->phase);
        }

        $parameters = $this->parameters($operation);
        $this->assertPendingBindingCurrent($operation, $project, $parameters);
        $expectedArgumentHash = $this->launchArgumentHash($operation, $project, $parameters, $effectAttemptToken);
        if ($operation->launch_argument_hash === null
            || ! hash_equals($operation->launch_argument_hash, $expectedArgumentHash)) {
            throw new ControlOperationRecoveryRequired('The persisted managed Git launch arguments no longer match this operation.');
        }
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'staged-effect reconciliation');
        try {
            if ($this->stagedEffectMatches($operation, $project, $effectAttemptToken, $parameters['control_ref'], $operation->target_control_oid)) {
                $this->progress($operation, $attemptToken, $operation->phase, [
                    'phase' => ControlOperationPhase::EFFECT_STAGED,
                ]);

                return false;
            }
            if ($parameters['pending_control_oid'] !== null) {
                throw new ControlOperationTerminalConflict(
                    'pending_control_head_mismatch',
                    'Der abgerufene Control-Head stimmt nicht mit der ausstehenden Bindung überein.',
                );
            }
            $this->paths->removeOwnedAttempt((string) $project->project_identifier, $operation->id, $effectAttemptToken);
        } finally {
            $lock->release();
        }

        return $this->stage($operation, $attemptToken, $operation->phase);
    }

    private function publishOutcome(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertPendingBindingCurrent($operation, $project, $parameters);
        $targetOid = $this->intentOid($operation);
        $effectAttemptToken = $operation->effect_attempt_token;
        if ($effectAttemptToken === null) {
            throw new ControlOperationRecoveryRequired('The staged managed-clone effect has no bound attempt token.');
        }
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed-clone publication');
        try {
            $repositoryPath = $this->paths->repositoryDirectory((string) $project->project_identifier);
            $published = $this->existingRefOid($repositoryPath, $parameters['control_ref'], $operation, 'published-state-inspection');
            if ($published !== $targetOid) {
                if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
                    $repositoryPath = $this->paths->publishStagedRepository(
                        (string) $project->project_identifier,
                        $operation->id,
                        $effectAttemptToken,
                    );
                } else {
                    $repositoryPath = $this->paths->assertRepository($repositoryPath);
                    $updated = $this->git->updateRef(
                        $repositoryPath,
                        $parameters['control_ref'],
                        $targetOid,
                        $published,
                        $this->context($operation, 'managed-ref-publish'),
                    );
                    if (! $updated->succeeded()) {
                        throw new RuntimeException($updated->errorOutput !== '' ? $updated->errorOutput : 'The managed live ref could not be published.');
                    }
                }
            }
            $this->assertPublishedEffect($operation, $project, $parameters['control_ref'], $targetOid);
            $this->progress($operation, $attemptToken, ControlOperationPhase::EFFECT_STAGED, [
                'phase' => ControlOperationPhase::OUTCOME_PUBLISHED,
            ]);
        } finally {
            $lock->release();
        }

        return false;
    }

    private function finalizeBinding(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $parameters = $this->parameters($operation);
        $this->assertPendingBindingCurrent($operation, $project, $parameters);
        $targetOid = $this->intentOid($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'control-binding finalization');
        try {
            $this->assertPublishedEffect($operation, $project, $parameters['control_ref'], $targetOid);
            DB::transaction(function () use ($operation, $project, $parameters, $targetOid, $attemptToken): void {
                $project->refresh();
                $expectedVersion = $parameters['expected_binding_version'];
                if (! ($project->control_oid === $targetOid
                    && $project->control_binding_version === $expectedVersion + 1
                    && PendingControlBinding::fromProject($project) === null)) {
                    $query = Project::query()
                        ->whereKey($project->getKey())
                        ->where('operation_lock_operation_id', $operation->id)
                        ->where('operation_lock_attempt_token', $attemptToken)
                        ->where('control_binding_version', $expectedVersion);
                    $updates = [
                        'control_oid' => $targetOid,
                        'control_binding_version' => DB::raw('control_binding_version + 1'),
                        'updated_at' => Date::now(),
                    ];
                    if ($parameters['pending_control_oid'] !== null) {
                        $query->whereNull('control_oid')
                            ->where('pending_control_ref', $parameters['control_ref'])
                            ->where('pending_control_oid', $parameters['pending_control_oid'])
                            ->where('pending_control_operation_id', $parameters['pending_source_operation_id']);
                        $updates += [
                            'pending_control_ref' => null,
                            'pending_control_oid' => null,
                            'pending_control_operation_id' => null,
                        ];
                    } else {
                        $query->when(
                            $operation->expected_control_commit === null,
                            static fn ($builder) => $builder->whereNull('control_oid'),
                            static fn ($builder) => $builder->where('control_oid', $operation->expected_control_commit),
                        )->whereNull('pending_control_ref')
                            ->whereNull('pending_control_oid')
                            ->whereNull('pending_control_operation_id');
                    }
                    $updated = $query->update($updates);
                    if ($updated !== 1) {
                        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                            throw new ControlOperationRetryableConflict('fencing_conflict', 'The binding publish lost its operation-attempt ownership.');
                        }

                        if ($parameters['pending_control_oid'] !== null) {
                            throw new ControlOperationTerminalConflict(
                                'pending_binding_conflict',
                                'Die ausstehende Control-Bindung wurde vor dem atomaren Konsum ersetzt.',
                            );
                        }

                        throw new ControlOperationRecoveryRequired('The control-binding version changed while this operation still owned the project lease.');
                    }
                }

                $this->progress($operation, $attemptToken, ControlOperationPhase::OUTCOME_PUBLISHED, [
                    'phase' => ControlOperationPhase::BINDING_FINALIZED,
                ]);
            });
        } finally {
            $lock->release();
        }

        return false;
    }

    private function complete(ControlOperation $operation, int $attemptToken): bool
    {
        $targetOid = $this->intentOid($operation);
        $this->cleanupFailedAttempt($operation, $attemptToken);
        DB::transaction(function () use ($operation, $attemptToken, $targetOid): void {
            if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                throw new ControlOperationRetryableConflict('lease_lost', 'The operation lease was lost before managed-clone completion.');
            }
            $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.$targetOid);
            $result = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $operation->id],
                [
                    'outcome' => ControlOperationOutcome::SUCCEEDED,
                    'result_binding' => $binding,
                    'safe_summary' => $operation->operation_type === ControlOperationType::MANAGED_CLONE
                        ? 'Der Managed-Clone wurde erstellt und gebunden.'
                        : 'Der Managed-Clone wurde aktualisiert und gebunden.',
                ],
            );
            if ($result->outcome !== ControlOperationOutcome::SUCCEEDED || ! hash_equals($result->result_binding, $binding)) {
                throw new RuntimeException('The existing managed-clone result has a different provenance binding.');
            }
            $this->progress($operation, $attemptToken, ControlOperationPhase::BINDING_FINALIZED, [
                'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                'state' => ControlOperationState::COMPLETED,
                'completed_at' => Date::now(),
            ]);
        });
        $operation->refresh();
        $this->lease->releaseTerminal($operation, $attemptToken);

        return true;
    }

    /** @return array{operation_id: string, target_control_oid: string}|null */
    public function activeIntentUnderLock(ControlOperation $operation, int $attemptToken): ?array
    {
        if ($operation->target_control_oid === null) {
            return null;
        }
        $project = $operation->project()->firstOrFail();
        $parameters = $this->parameters($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed effect inspection');
        try {
            $repository = $this->paths->repositoryDirectory((string) $project->project_identifier);
            $oid = $this->existingRefOid(
                $repository,
                $parameters['control_ref'],
                $operation,
                'managed-effect-inspection',
            );

            if (is_dir($repository) && ! is_link($repository)) {
                foreach ($this->git->refs(
                    $this->paths->assertRepository($repository),
                    $this->context($operation, 'managed-effect-ref-inventory'),
                ) as $name => $refOid) {
                    if (! in_array($name, $this->configuration->managedRefAllowlist, true)
                        && ! str_starts_with($name, 'refs/ai6/attempts/'.$operation->id.'/')) {
                        throw new ControlOperationRecoveryRequired('The managed repository contains a ref outside the configured allowlist.');
                    }
                }
            }

            if ($oid !== $operation->target_control_oid && $oid !== $project->control_oid) {
                throw new ControlOperationRecoveryRequired('The managed repository and active control binding are inconsistent.');
            }

            return $oid === $operation->target_control_oid
                ? ['operation_id' => $operation->id, 'target_control_oid' => $oid]
                : null;
        } finally {
            $lock->release();
        }
    }

    public function cleanupFailedAttempt(ControlOperation $operation, int $attemptToken): void
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed-attempt cleanup');
        try {
            $repositoryPath = $this->paths->repositoryDirectory((string) $project->project_identifier);
            if (is_dir($repositoryPath) && ! is_link($repositoryPath)) {
                $repository = $this->paths->assertRepository($repositoryPath);
                foreach ($this->git->refs($repository, $this->context($operation, 'managed-ref-cleanup')) as $ref => $oid) {
                    if (str_starts_with($ref, 'refs/ai6/attempts/'.$operation->id.'/')) {
                        $deleted = $this->git->deleteAttemptRef(
                            $repository,
                            $ref,
                            $oid,
                            $this->context($operation, 'managed-ref-cleanup'),
                        );
                        if (! $deleted->succeeded()) {
                            throw new RuntimeException('An attempt-scoped Git ref could not be safely removed.');
                        }
                    }
                }
            }
            $this->paths->removeOwnedOperation((string) $project->project_identifier, $operation->id);
        } finally {
            $lock->release();
        }
    }

    /** @return array{text: string, hash: string, effect_hash: string} */
    public function recoveryFinding(ControlOperation $operation, int $attemptToken, string $deviation): array
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed recovery inspection');
        try {
            $snapshot = $this->effectSnapshot($operation, $project);
            $effectHash = $this->snapshotHash($snapshot);
            $findingBytes = json_encode([
                'operation_id' => $operation->id,
                'effect_snapshot_hash' => $effectHash,
                'target_control_oid' => $operation->target_control_oid,
                'effect_attempt_token' => $operation->effect_attempt_token,
                'deviation' => $deviation,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return [
                'text' => sprintf(
                    'Beobachteter Managed-Clone-Stand (SHA-256 %s): %s. Abweichung: %s',
                    $effectHash,
                    json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    $deviation,
                ),
                'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$findingBytes),
                'effect_hash' => $effectHash,
            ];
        } finally {
            $lock->release();
        }
    }

    public function retryRecovery(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->assertType($operation);
        $project = $operation->project()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $parameters = $this->parameters($operation);
        $targetOid = $this->intentOid($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed recovery retry');
        try {
            $this->assertRecoveryEffectUnchanged($operation, $project);
            $this->assertPublishedEffect($operation, $project, $parameters['control_ref'], $targetOid);
            for ($reconciliation = 0; $reconciliation < $this->configuration->reconciliationBudget; $reconciliation++) {
                $resolved = DB::transaction(function () use ($operation, $decision, $project, $attemptToken, $targetOid): bool {
                    $project->refresh();
                    if ($project->control_oid !== $targetOid) {
                        $updated = Project::query()
                            ->whereKey($project->getKey())
                            ->where('operation_lock_operation_id', $operation->id)
                            ->where('operation_lock_attempt_token', $attemptToken)
                            ->where('control_binding_version', $project->control_binding_version)
                            ->update([
                                'control_oid' => $targetOid,
                                'control_binding_version' => DB::raw('control_binding_version + 1'),
                                'updated_at' => Date::now(),
                            ]);
                        if ($updated !== 1) {
                            return false;
                        }
                    }

                    $updated = ControlOperation::query()
                        ->whereKey($operation->id)
                        ->where('current_attempt_token', $attemptToken)
                        ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                        ->where('version', $operation->version)
                        ->where('finding_hash', $operation->finding_hash)
                        ->update([
                            'phase' => ControlOperationPhase::BINDING_FINALIZED,
                            'state' => ControlOperationState::RUNNING,
                            'finding_text' => null,
                            'finding_hash' => null,
                            'recovery_attempt_token' => null,
                            'recovery_version' => null,
                            'recovery_effect_hash' => null,
                            'version' => DB::raw('version + 1'),
                            'updated_at' => Date::now(),
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('The managed recovery decision lost its compare-and-swap binding.');
                    }
                    $this->markDecisionApplied($decision);

                    return true;
                });
                if ($resolved) {
                    return;
                }
            }

            throw new ControlOperationRecoveryRequired('The managed recovery binding compare-and-swap exhausted its configured budget.');
        } finally {
            $lock->release();
        }
    }

    public function adoptExternalState(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->retryRecovery($operation, $decision);
    }

    public function abandon(ControlOperation $operation, ControlOperationRecoveryDecision $decision): void
    {
        $this->assertType($operation);
        $project = $operation->project()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'managed operation abandonment');
        try {
            $this->assertRecoveryEffectUnchanged($operation, $project);
            $snapshot = $this->effectSnapshot($operation, $project);
            if (($snapshot['live_control_oid'] ?? null) !== $project->control_oid) {
                throw new ControlOperationRecoveryRequired('An inconsistent published managed-clone effect cannot be abandoned.');
            }
            DB::transaction(function () use ($operation, $decision, $attemptToken): void {
                $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.'abandoned');
                ControlOperationResult::query()->firstOrCreate(
                    ['control_operation_id' => $operation->id],
                    [
                        'outcome' => ControlOperationOutcome::ABANDONED,
                        'result_binding' => $binding,
                        'safe_summary' => 'Die Managed-Clone-Operation wurde mit gebundener Evidenz abgebrochen.',
                    ],
                );
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
                    throw new RuntimeException('The managed abandonment decision lost its compare-and-swap binding.');
                }
                $this->markDecisionApplied($decision);
            });
        } finally {
            $lock->release();
        }
        $this->cleanupFailedAttempt($operation->refresh(), $attemptToken);
        $this->lease->releaseTerminal($operation->refresh(), $attemptToken);
    }

    /**
     * @param  array{control_ref: string, expected_binding_version: int, pending_source_operation_id: string|null, pending_binding_version: int|null, pending_control_oid: string|null}  $parameters
     */
    private function assertExecutionContract(ControlOperation $operation, Project $project, array $parameters): void
    {
        $this->assertPendingBindingCurrent($operation, $project, $parameters);
        if ($project->provisioning_status !== ProjectProvisioningStatus::PROVISIONED
            || $project->remote === null
            || $project->deploy_key_reference === null
            || $project->control_branch !== $parameters['control_ref']
            || ! in_array($parameters['control_ref'], $this->configuration->managedRefAllowlist, true)
            || $project->control_binding_version !== $parameters['expected_binding_version']) {
            throw new RuntimeException('Project provenance changed before managed Git execution.');
        }
    }

    /**
     * @return array{control_ref: string, expected_binding_version: int, pending_source_operation_id: string|null, pending_binding_version: int|null, pending_control_oid: string|null}
     */
    private function parameters(ControlOperation $operation): array
    {
        try {
            $decoded = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Managed-clone operation parameters are malformed.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Managed-clone operation parameters are incomplete.');
        }
        $parameters = $operation->operation_type->parameters($decoded);
        if (! is_string($parameters['control_ref'])
            || ! is_int($parameters['expected_binding_version'])
            || $parameters['expected_binding_version'] < 0
            || ! hash_equals($operation->operation_parameters_jcs, $this->canonicalJson->normalizeAndEncode($parameters))) {
            throw new RuntimeException('Managed-clone operation parameters are not canonical.');
        }

        if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
            return $parameters + [
                'pending_source_operation_id' => null,
                'pending_binding_version' => null,
                'pending_control_oid' => null,
            ];
        }

        $pendingValues = [
            $parameters['pending_source_operation_id'],
            $parameters['pending_binding_version'],
            $parameters['pending_control_oid'],
        ];
        $allNull = $pendingValues === [null, null, null];
        $complete = is_string($parameters['pending_source_operation_id'])
            && is_int($parameters['pending_binding_version'])
            && $parameters['pending_binding_version'] >= 0
            && $parameters['pending_binding_version'] === $parameters['expected_binding_version']
            && is_string($parameters['pending_control_oid'])
            && preg_match('/\A[0-9a-f]{64}\z/D', $parameters['pending_control_oid']) === 1;
        if (! $allNull && ! $complete) {
            throw new RuntimeException('Managed-fetch pending-binding parameters are incomplete.');
        }

        return $parameters;
    }

    /**
     * @param  array{control_ref: string, expected_binding_version: int, pending_source_operation_id: string|null, pending_binding_version: int|null, pending_control_oid: string|null}  $parameters
     */
    private function assertPendingBindingCurrent(
        ControlOperation $operation,
        Project $project,
        array $parameters,
    ): void {
        $pending = PendingControlBinding::fromProject($project);
        if ($parameters['pending_control_oid'] === null) {
            if ($pending !== null || $project->control_oid !== $operation->expected_control_commit) {
                throw new ControlOperationTerminalConflict(
                    'pending_binding_conflict',
                    'Die Control-Bindung stimmt nicht mehr mit dem Fetchauftrag überein.',
                );
            }

            return;
        }

        if ($operation->operation_type !== ControlOperationType::MANAGED_FETCH
            || $operation->expected_control_commit !== null
            || $project->control_oid !== null
            || $pending === null
            || $pending->ref !== $parameters['control_ref']
            || $pending->sourceOperationId !== $parameters['pending_source_operation_id']
            || $pending->version !== $parameters['pending_binding_version']
            || $pending->oid !== $parameters['pending_control_oid']) {
            throw new ControlOperationTerminalConflict(
                'pending_binding_conflict',
                'Die ausstehende Control-Bindung wurde nach dem Anlegen des Fetchauftrags ersetzt.',
            );
        }
    }

    private function assertStagedEffect(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        string $controlRef,
        string $targetOid,
        bool $pendingBinding,
    ): void {
        if (! $this->stagedEffectMatches($operation, $project, $attemptToken, $controlRef, $targetOid)) {
            if ($pendingBinding) {
                throw new ControlOperationTerminalConflict(
                    'pending_control_head_mismatch',
                    'Der abgerufene Control-Head stimmt nicht mit der ausstehenden Bindung überein.',
                );
            }

            throw new RuntimeException('The staged managed Git result does not match its remote-probe binding.');
        }
    }

    private function stagedEffectMatches(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        string $controlRef,
        string $targetOid,
    ): bool {
        $repository = $operation->operation_type === ControlOperationType::MANAGED_CLONE
            ? $this->paths->stagedRepository((string) $project->project_identifier, $operation->id, $attemptToken)
            : $this->paths->repositoryDirectory((string) $project->project_identifier);
        if (! is_dir($repository) || is_link($repository)) {
            return false;
        }
        $repository = $this->paths->assertRepository($repository);
        $ref = $operation->operation_type === ControlOperationType::MANAGED_CLONE
            ? $controlRef
            : ManagedProjectPath::attemptRef($operation->id, $attemptToken);
        $result = $operation->operation_type === ControlOperationType::MANAGED_CLONE
            ? $this->git->resolveRef($repository, $ref, $this->context($operation, 'staged-clone-validation'))
            : $this->git->resolveAttemptRef($repository, $ref, $this->context($operation, 'staged-fetch-validation'));
        if (! $result->succeeded() || trim($result->output) !== $targetOid) {
            return false;
        }
        if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
            foreach ($this->git->refs($repository, $this->context($operation, 'staged-clone-ref-inventory')) as $name => $oid) {
                if (! in_array($name, $this->configuration->managedRefAllowlist, true)
                    || ($name === $controlRef && $oid !== $targetOid)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function assertPublishedEffect(
        ControlOperation $operation,
        Project $project,
        string $controlRef,
        string $targetOid,
    ): void {
        $repository = $this->paths->assertRepository(
            $this->paths->repositoryDirectory((string) $project->project_identifier),
        );
        $refs = $this->git->refs($repository, $this->context($operation, 'published-ref-inventory'));
        if (($refs[$controlRef] ?? null) !== $targetOid) {
            throw new ControlOperationRecoveryRequired('The published managed-clone ref differs from the persisted intent.');
        }
        foreach ($refs as $name => $oid) {
            if (! in_array($name, $this->configuration->managedRefAllowlist, true)
                && ! str_starts_with($name, 'refs/ai6/attempts/'.$operation->id.'/')) {
                throw new ControlOperationRecoveryRequired('The managed repository contains a ref outside the configured allowlist.');
            }
        }
    }

    private function existingRefOid(
        string $repository,
        string $ref,
        ControlOperation $operation,
        string $purpose,
    ): ?string {
        if (! is_dir($repository) || is_link($repository)) {
            return null;
        }
        $repository = $this->paths->assertRepository($repository);
        $result = $this->git->resolveRef($repository, $ref, $this->context($operation, $purpose));
        if (! $result->succeeded()) {
            if ($result->exitCode === 1
                && trim($result->output) === ''
                && trim($result->errorOutput) === '') {
                return null;
            }

            throw new ControlOperationRecoveryRequired('The managed Git ref could not be resolved safely.');
        }
        $oid = trim($result->output);

        if (! $this->validOid($oid)) {
            throw new ControlOperationRecoveryRequired('The managed Git ref could not be resolved safely.');
        }

        return $oid;
    }

    /**
     * @param  array{control_ref: string, expected_binding_version: int, pending_source_operation_id: string|null, pending_binding_version: int|null, pending_control_oid: string|null}  $parameters
     */
    private function launchArgumentHash(
        ControlOperation $operation,
        Project $project,
        array $parameters,
        int $attemptToken,
    ): string {
        if ($operation->operation_type === ControlOperationType::MANAGED_CLONE) {
            $staged = $this->paths->stagedRepository(
                (string) $project->project_identifier,
                $operation->id,
                $attemptToken,
            );

            return $this->git->managedCloneArgumentHash(
                (string) $project->remote,
                $parameters['control_ref'],
                basename($staged),
                dirname($staged),
                (string) $project->deploy_key_reference,
                $this->configuration->knownHostsFile,
                (string) $project->host_key_fingerprint,
                $this->context($operation, 'managed-clone'),
            );
        }

        return $this->git->fetchArgumentHash(
            (string) $project->remote,
            $parameters['control_ref'],
            ManagedProjectPath::attemptRef($operation->id, $attemptToken),
            $this->paths->assertRepository(
                $this->paths->repositoryDirectory((string) $project->project_identifier),
            ),
            (string) $project->deploy_key_reference,
            $this->configuration->knownHostsFile,
            (string) $project->host_key_fingerprint,
            $this->context($operation, 'managed-fetch'),
        );
    }

    private function intentOid(ControlOperation $operation): string
    {
        if ($operation->target_control_oid === null || ! $this->validOid($operation->target_control_oid)) {
            throw new ControlOperationRecoveryRequired('The managed-clone operation has no valid persisted target OID.');
        }

        return $operation->target_control_oid;
    }

    /** @return array<string, int|string|null> */
    private function effectSnapshot(ControlOperation $operation, Project $project): array
    {
        $parameters = $this->parameters($operation);
        $repository = $this->paths->repositoryDirectory((string) $project->project_identifier);

        return [
            'repository_type' => is_link($repository) ? 'link' : (is_dir($repository) ? 'directory' : 'absent'),
            'live_control_oid' => $this->existingRefOid($repository, $parameters['control_ref'], $operation, 'recovery-snapshot'),
            'project_control_oid' => $project->control_oid,
            'control_binding_version' => $project->control_binding_version,
            'target_control_oid' => $operation->target_control_oid,
            'effect_attempt_token' => $operation->effect_attempt_token,
        ];
    }

    /** @param array<string, int|string|null> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        return hash('sha256', "AI6-MANAGED-CLONE-EFFECT-V1\0".json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function assertRecoveryEffectUnchanged(ControlOperation $operation, Project $project): void
    {
        if ($operation->recovery_effect_hash === null
            || ! hash_equals($operation->recovery_effect_hash, $this->snapshotHash($this->effectSnapshot($operation, $project)))) {
            throw new ControlOperationRecoveryRequired('The managed-clone effect changed after the recovery finding.');
        }
    }

    private function recoveryAttemptToken(ControlOperation $operation): int
    {
        if ($operation->current_attempt_token === null) {
            throw new RuntimeException('The managed recovery decision has no bound operation attempt.');
        }

        return $operation->current_attempt_token;
    }

    private function markDecisionApplied(ControlOperationRecoveryDecision $decision): void
    {
        $updated = ControlOperationRecoveryDecision::query()
            ->whereKey($decision->id)
            ->where('state', 'pending')
            ->update(['state' => 'applied', 'applied_at' => Date::now(), 'updated_at' => Date::now()]);
        if ($updated !== 1) {
            throw new RuntimeException('The managed recovery decision lost its single-consumption binding.');
        }
    }

    private function acquireEffectLock(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        string $section,
    ): EffectLockHandle {
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', sprintf('The operation lease was lost before %s.', $section));
        }
        $lock = $this->effectLock->acquire($this->lockNames->forProject((string) $project->project_identifier));
        if (! $lock->acquired() || $lock->handle === null) {
            $conflict = match ($lock->outcome) {
                EffectLockOutcome::UNAVAILABLE => 'effect_lock_unavailable',
                EffectLockOutcome::CONFLICT => 'effect_lock_conflict',
                EffectLockOutcome::CONFIGURATION_ERROR => 'effect_lock_configuration',
                EffectLockOutcome::ACQUIRED => 'effect_lock_invalid_result',
            };
            throw new ControlOperationRetryableConflict($conflict, sprintf('The effect lock for %s is unavailable: %s', $section, $lock->message));
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            $lock->handle->release();

            throw new ControlOperationRetryableConflict('lease_lost', sprintf('The operation lease was lost under the effect lock for %s.', $section));
        }

        return $lock->handle;
    }

    /** @param array<string, mixed> $updates */
    private function progress(
        ControlOperation $operation,
        int $attemptToken,
        ControlOperationPhase $phase,
        array $updates,
    ): void {
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new ControlOperationRetryableConflict('lease_lost', 'The operation lease was lost before managed-clone progress persistence.');
        }
        $updates['version'] = DB::raw('version + 1');
        $updates['updated_at'] = Date::now();
        $updated = ControlOperation::query()
            ->whereKey($operation->id)
            ->where('current_attempt_token', $attemptToken)
            ->where('phase', $phase)
            ->where('state', ControlOperationState::RUNNING)
            ->update($updates);
        if ($updated !== 1) {
            throw new RuntimeException('Managed-clone progress lost its compare-and-swap binding.');
        }
        $operation->refresh();
    }

    private function context(ControlOperation $operation, string $purpose): RedactionContext
    {
        return new RedactionContext((string) $operation->project_id, $operation->id, $purpose);
    }

    private function assertType(ControlOperation $operation): void
    {
        if (! in_array($operation->operation_type, [ControlOperationType::MANAGED_CLONE, ControlOperationType::MANAGED_FETCH], true)) {
            throw new RuntimeException('The operation is not a managed-clone operation.');
        }
    }

    private function validOid(string $oid): bool
    {
        return preg_match('/\A[0-9a-f]{64}\z/D', $oid) === 1;
    }
}
