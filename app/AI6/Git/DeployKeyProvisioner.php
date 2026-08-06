<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\BlockedStartOutcome;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\EffectLockHandle;
use App\AI6\Shared\Process\EffectLockOutcome;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final readonly class DeployKeyProvisioner
{
    private const PRIVATE_KEY = 'id_ed25519';

    private const PUBLIC_KEY = 'id_ed25519.pub';

    private const INTENT = 'intent.json';

    public function __construct(
        private ControlOperationConfiguration $configuration,
        private ManagedProjectPath $paths,
        private ProjectOperationLease $lease,
        private ProjectEffectLockName $lockNames,
        private ProcessConfiguration $processConfiguration,
        private ControlProcessRunner $processes,
        private EffectLock $effectLock,
        private ProjectPolicy $projectPolicy,
        private ControlOperationAuthorizationSnapshot $authorizationSnapshots,
    ) {}

    public function advance(ControlOperation $operation, int $attemptToken): bool
    {
        $operation->refresh();

        return match ($operation->phase) {
            ControlOperationPhase::CLAIMED => $this->generate($operation, $attemptToken),
            ControlOperationPhase::LAUNCH_INTENT,
            ControlOperationPhase::PROCESS_STARTED => $this->reconcileLaunch($operation, $attemptToken),
            ControlOperationPhase::KEY_GENERATED => $this->activate($operation, $attemptToken),
            ControlOperationPhase::KEY_ACTIVATED => $this->finalizeProvisioning($operation, $attemptToken),
            ControlOperationPhase::PROVISIONING_FINALIZED => $this->complete($operation, $attemptToken),
            ControlOperationPhase::ATTEMPT_COMPLETED => true,
            ControlOperationPhase::RECOVERY_REQUIRED => throw new ControlOperationRecoveryRequired('The operation requires a human recovery decision.'),
            ControlOperationPhase::QUEUED => throw new RuntimeException('The operation was not claimed before execution.'),
        };
    }

    /** @return array{schema: int, operation_id: string, request_hash: string, attempt_token: int, public_key_fingerprint: string}|null */
    public function activeIntent(ControlOperation $operation): ?array
    {
        $project = $operation->project()->firstOrFail();
        $directory = $this->paths->activeDirectory((string) $project->project_identifier);
        if (! file_exists($directory) && ! is_link($directory)) {
            return null;
        }
        if (! is_dir($directory) || is_link($directory)) {
            throw new ControlOperationRecoveryRequired('The active deploy-key path has an unsafe type.');
        }

        return $this->readIntent($directory);
    }

    /** @return array{schema: int, operation_id: string, request_hash: string, attempt_token: int, public_key_fingerprint: string}|null */
    public function activeIntentUnderLock(ControlOperation $operation, int $attemptToken): ?array
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'active-effect inspection');

        try {
            return $this->activeIntent($operation);
        } finally {
            $lock->release();
        }
    }

    private function generate(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $actor = $operation->actor()->firstOrFail();
        if (! $this->projectPolicy->provisionDeployKey($actor, $project)
            || ! $this->authorizationSnapshots->matchesCurrent($operation, $actor, $project)) {
            throw new RuntimeException('Authorization changed before the deploy-key process started.');
        }

        $bundle = $this->paths->prepareBundle((string) $project->project_identifier, $operation->id, $attemptToken);
        $privateKey = $bundle.'/'.self::PRIVATE_KEY;
        if (file_exists($privateKey) || is_link($privateKey)) {
            throw new RuntimeException('The deploy-key staging target already exists.');
        }

        $arguments = $this->keygenArguments($operation, $privateKey);
        $encodedArguments = json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->progress($operation, $attemptToken, ControlOperationPhase::CLAIMED, [
            'phase' => ControlOperationPhase::LAUNCH_INTENT,
            'effect_attempt_token' => $attemptToken,
            'launch_argument_hash' => hash('sha256', $encodedArguments),
        ]);

        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new RuntimeException('The operation lease was lost before process start.');
        }

        $request = new ProcessRequest(
            $arguments,
            $bundle,
            [],
            [],
            new RedactionContext((string) $project->getKey(), $operation->id, 'deploy-key-generation'),
        );
        $start = $this->processes->startBlocked($request, $this->lockNames->forProject((string) $project->project_identifier));
        if (! $start->ready() || $start->process === null) {
            if (in_array($start->outcome, [
                BlockedStartOutcome::LOCK_UNAVAILABLE,
                BlockedStartOutcome::LOCK_CONFLICT,
                BlockedStartOutcome::CONFIGURATION_ERROR,
            ], true)) {
                throw new ControlOperationRetryableConflict(
                    'effect_lock_'.$start->outcome->value,
                    'Deploy-key generation encountered a named effect-lock conflict: '.$start->message,
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
                    throw new RuntimeException('The operation lease was lost while the deploy-key process was running.');
                }
            }, $this->configuration->heartbeatSeconds);
        } catch (\Throwable $exception) {
            $blocked->cancel();

            throw $exception;
        }

        $operation->refresh();
        $actor->refresh();
        $project->refresh();
        if (! $result->succeeded()
            || $operation->process_id !== $blocked->processId
            || ! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)
            || ! $this->projectPolicy->provisionDeployKey($actor, $project)) {
            throw new RuntimeException($result->errorOutput !== '' ? $result->errorOutput : 'Deploy-key generation did not produce an authorized result.');
        }

        $publicKeyPath = $bundle.'/'.self::PUBLIC_KEY;
        $this->paths->assertRegularFile($privateKey, $bundle);
        $this->paths->assertRegularFile($publicKeyPath, $bundle);
        $this->assertGeneratedResultBinding($operation, $publicKeyPath);
        $this->assertKeyPairMatches($operation, $privateKey, $publicKeyPath, $bundle);
        $fingerprint = hash_file('sha256', $publicKeyPath);
        if (! is_string($fingerprint)) {
            throw new RuntimeException('The generated deploy key could not be fingerprinted.');
        }

        $intent = json_encode([
            'schema' => 1,
            'operation_id' => $operation->id,
            'request_hash' => $operation->request_hash,
            'attempt_token' => $attemptToken,
            'public_key_fingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($bundle.'/'.self::INTENT, $intent."\n", LOCK_EX) === false) {
            throw new RuntimeException('The deploy-key activation intent could not be persisted.');
        }
        chmod($privateKey, 0600);
        chmod($publicKeyPath, 0644);
        chmod($bundle.'/'.self::INTENT, 0600);

        $this->progress($operation, $attemptToken, ControlOperationPhase::PROCESS_STARTED, [
            'phase' => ControlOperationPhase::KEY_GENERATED,
            'key_fingerprint' => $fingerprint,
        ]);

        return false;
    }

    private function reconcileLaunch(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $effectAttemptToken = $operation->effect_attempt_token;
        if ($effectAttemptToken === null) {
            throw new ControlOperationRecoveryRequired('The launch intent has no bound effect attempt.');
        }
        $bundle = $this->paths->prepareBundle((string) $project->project_identifier, $operation->id, $effectAttemptToken);
        $encodedArguments = json_encode(
            $this->keygenArguments($operation, $bundle.'/'.self::PRIVATE_KEY),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if ($operation->launch_argument_hash === null
            || ! hash_equals($operation->launch_argument_hash, hash('sha256', $encodedArguments))) {
            throw new ControlOperationRecoveryRequired('The persisted launch arguments no longer match this operation.');
        }

        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'launch reconciliation');
        try {
            $active = $this->activeIntent($operation);
            if ($active !== null) {
                if ($this->intentMatches($active, $operation)) {
                    $this->progress($operation, $attemptToken, $operation->phase, [
                        'phase' => ControlOperationPhase::KEY_ACTIVATED,
                        'key_fingerprint' => $active['public_key_fingerprint'],
                    ]);

                    return false;
                }

                throw new ControlOperationRecoveryRequired('An active deploy key is not bound to this operation.');
            }

            if (is_file($bundle.'/'.self::INTENT)) {
                $intent = $this->readIntent($bundle);
                if ($this->intentMatches($intent, $operation)) {
                    $this->progress($operation, $attemptToken, $operation->phase, [
                        'phase' => ControlOperationPhase::KEY_GENERATED,
                        'key_fingerprint' => $intent['public_key_fingerprint'],
                    ]);

                    return false;
                }
            }

            $this->paths->removeOwnedAttempt((string) $project->project_identifier, $operation->id, $effectAttemptToken);
            $this->progress($operation, $attemptToken, $operation->phase, [
                'phase' => ControlOperationPhase::CLAIMED,
                'process_id' => null,
                'process_started_at' => null,
                'effect_attempt_token' => null,
                'launch_argument_hash' => null,
            ]);
        } finally {
            $lock->release();
        }

        return false;
    }

    private function activate(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'deploy-key activation');

        try {
            if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                throw new RuntimeException('The operation lease was lost before deploy-key activation.');
            }

            $active = $this->paths->activeDirectory((string) $project->project_identifier);
            if (file_exists($active) || is_link($active)) {
                if (! is_dir($active) || is_link($active)) {
                    throw new ControlOperationRecoveryRequired('The active deploy-key path has an unsafe type.');
                }

                $intent = $this->readIntent($active);
                if (! $this->intentMatches($intent, $operation)) {
                    throw new ControlOperationRecoveryRequired('An active deploy key is not bound to this operation.');
                }
                $this->assertKeyPairMatches(
                    $operation,
                    $active.'/'.self::PRIVATE_KEY,
                    $active.'/'.self::PUBLIC_KEY,
                    $active,
                );
            } else {
                $effectAttemptToken = $operation->effect_attempt_token;
                if ($effectAttemptToken === null) {
                    throw new ControlOperationRecoveryRequired('The generated key has no bound effect attempt.');
                }
                $bundle = $this->paths->prepareBundle((string) $project->project_identifier, $operation->id, $effectAttemptToken);
                $intent = $this->readIntent($bundle);
                if (! $this->intentMatches($intent, $operation)) {
                    throw new ControlOperationRecoveryRequired('The staged deploy key is not bound to this operation.');
                }
                $this->assertKeyPairMatches(
                    $operation,
                    $bundle.'/'.self::PRIVATE_KEY,
                    $bundle.'/'.self::PUBLIC_KEY,
                    $bundle,
                );
                if (! rename($bundle, $active)) {
                    throw new RuntimeException('The deploy-key bundle could not be atomically activated.');
                }
            }

            $this->progress($operation, $attemptToken, ControlOperationPhase::KEY_GENERATED, [
                'phase' => ControlOperationPhase::KEY_ACTIVATED,
            ]);
        } finally {
            $lock->release();
        }

        return false;
    }

    private function finalizeProvisioning(ControlOperation $operation, int $attemptToken): bool
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'provisioning finalization');

        try {
            $active = $this->paths->activeDirectory((string) $project->project_identifier);
            $intent = $this->readIntent($active);
            if (! $this->intentMatches($intent, $operation)) {
                throw new ControlOperationRecoveryRequired('The active deploy key lost its operation binding before finalization.');
            }

            $publicPath = $active.'/'.self::PUBLIC_KEY;
            $privatePath = $active.'/'.self::PRIVATE_KEY;
            $this->paths->assertRegularFile($publicPath, $active);
            $this->paths->assertRegularFile($privatePath, $active);
            $this->assertKeyPairMatches($operation, $privatePath, $publicPath, $active);
            $publicKey = file_get_contents($publicPath);
            if (! is_string($publicKey) || trim($publicKey) === '') {
                throw new RuntimeException('The active public deploy key could not be read.');
            }

            $project->refresh();
            $normalizedPublicKey = rtrim($publicKey)."\n";
            if ($project->provisioning_status === ProjectProvisioningStatus::PROVISIONED) {
                if ($project->provisioning_operation_id !== $operation->id
                    || $project->deploy_key_reference !== $privatePath
                    || $project->public_deploy_key !== $normalizedPublicKey) {
                    throw new ControlOperationRecoveryRequired('The provisioned project does not match the active deploy key.');
                }

                $this->progress($operation, $attemptToken, ControlOperationPhase::KEY_ACTIVATED, [
                    'phase' => ControlOperationPhase::PROVISIONING_FINALIZED,
                ]);

                return false;
            }

            $updated = Project::query()
                ->whereKey($project->getKey())
                ->where('operation_lock_operation_id', $operation->id)
                ->where('operation_lock_attempt_token', $attemptToken)
                ->where('provisioning_operation_id', $operation->id)
                ->where('provisioning_status', ProjectProvisioningStatus::PROVISIONING)
                ->when(
                    $operation->expected_control_commit === null,
                    static fn ($query) => $query->whereNull('control_oid'),
                    static fn ($query) => $query->where('control_oid', $operation->expected_control_commit),
                )
                ->update([
                    'deploy_key_reference' => $privatePath,
                    'public_deploy_key' => $normalizedPublicKey,
                    'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
                ]);
            if ($updated !== 1) {
                throw new ControlOperationRecoveryRequired('Project provenance changed before deploy-key finalization.');
            }

            $this->progress($operation, $attemptToken, ControlOperationPhase::KEY_ACTIVATED, [
                'phase' => ControlOperationPhase::PROVISIONING_FINALIZED,
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }

    private function complete(ControlOperation $operation, int $attemptToken): bool
    {
        $this->cleanupFailedAttempt($operation, $attemptToken);

        DB::transaction(function () use ($operation, $attemptToken): void {
            if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
                throw new RuntimeException('The operation lease was lost before result finalization.');
            }

            $binding = hash('sha256', "AI6-CONTROL-RESULT-V1\0".$operation->id.$operation->request_hash.$operation->key_fingerprint);
            $result = ControlOperationResult::query()->firstOrCreate(
                ['control_operation_id' => $operation->id],
                [
                    'outcome' => ControlOperationOutcome::SUCCEEDED,
                    'result_binding' => $binding,
                    'safe_summary' => 'Deploy-key provisioning completed.',
                ],
            );
            if ($result->outcome !== ControlOperationOutcome::SUCCEEDED
                || ! hash_equals($result->result_binding, $binding)) {
                throw new RuntimeException('The existing operation result has a different provenance binding.');
            }

            $this->progress($operation, $attemptToken, ControlOperationPhase::PROVISIONING_FINALIZED, [
                'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
                'state' => ControlOperationState::COMPLETED,
                'completed_at' => Date::now(),
            ]);
        });
        $operation->refresh();
        $this->lease->releaseTerminal($operation, $attemptToken);

        return true;
    }

    public function retryRecovery(
        ControlOperation $operation,
        ControlOperationRecoveryDecision $decision,
    ): void {
        $project = $operation->project()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'recovery retry');

        try {
            $this->assertRecoveryEffectUnchanged($operation, $project);
            $active = $this->activeIntent($operation);
            if ($active !== null) {
                if (! $this->intentMatches($active, $operation)) {
                    throw new ControlOperationRecoveryRequired('The active deploy key still differs from the operation intent.');
                }

                $phase = $project->provisioning_status === ProjectProvisioningStatus::PROVISIONED
                    ? ControlOperationPhase::PROVISIONING_FINALIZED
                    : ControlOperationPhase::KEY_ACTIVATED;
            } else {
                if ($operation->effect_attempt_token !== null) {
                    $this->paths->removeOwnedAttempt(
                        (string) $project->project_identifier,
                        $operation->id,
                        $operation->effect_attempt_token,
                    );
                }
                $phase = ControlOperationPhase::CLAIMED;
            }

            DB::transaction(function () use ($operation, $decision, $attemptToken, $phase): void {
                $updated = ControlOperation::query()
                    ->whereKey($operation->id)
                    ->where('current_attempt_token', $attemptToken)
                    ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                    ->where('version', $operation->version)
                    ->where('finding_hash', $operation->finding_hash)
                    ->update([
                        'phase' => $phase,
                        'state' => ControlOperationState::RUNNING,
                        'effect_attempt_token' => $phase === ControlOperationPhase::CLAIMED ? null : $operation->effect_attempt_token,
                        'process_id' => null,
                        'process_started_at' => null,
                        'finding_text' => null,
                        'finding_hash' => null,
                        'recovery_attempt_token' => null,
                        'recovery_version' => null,
                        'recovery_effect_hash' => null,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => Date::now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The recovery retry lost its compare-and-swap binding.');
                }
                $this->markDecisionApplied($decision);
            });
        } finally {
            $lock->release();
        }
    }

    public function cleanupFailedAttempt(ControlOperation $operation, int $attemptToken): void
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'attempt cleanup');

        try {
            if ($operation->state === ControlOperationState::RECOVERY_REQUIRED) {
                $this->assertRecoveryEffectUnchanged($operation, $project);
            }
            if ($operation->effect_attempt_token !== null) {
                $this->paths->removeOwnedAttempt(
                    (string) $project->project_identifier,
                    $operation->id,
                    $operation->effect_attempt_token,
                );
            }
        } finally {
            $lock->release();
        }
    }

    public function adoptExternalState(
        ControlOperation $operation,
        ControlOperationRecoveryDecision $decision,
    ): void {
        $project = $operation->project()->firstOrFail();
        $attemptToken = $this->recoveryAttemptToken($operation);
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'external-state adoption');

        try {
            if ($decision->application_fingerprint === null) {
                $this->assertRecoveryEffectUnchanged($operation, $project);
            }
            $active = $this->paths->activeDirectory((string) $project->project_identifier);
            if (! is_dir($active) || is_link($active)) {
                throw new RuntimeException('There is no safe external deploy-key state to adopt.');
            }
            $publicPath = $active.'/'.self::PUBLIC_KEY;
            $privatePath = $active.'/'.self::PRIVATE_KEY;
            $this->paths->assertRegularFile($publicPath, $active);
            $this->paths->assertRegularFile($privatePath, $active);
            $this->assertKeyPairMatches($operation, $privatePath, $publicPath, $active);
            $fingerprint = hash_file('sha256', $publicPath);
            if (! is_string($fingerprint)) {
                throw new RuntimeException('The external deploy key could not be fingerprinted.');
            }

            if ($decision->application_fingerprint === null) {
                $marked = ControlOperationRecoveryDecision::query()
                    ->whereKey($decision->id)
                    ->where('state', 'pending')
                    ->whereNull('application_attempt_token')
                    ->whereNull('application_fingerprint')
                    ->update([
                        'application_attempt_token' => $attemptToken,
                        'application_fingerprint' => $fingerprint,
                        'updated_at' => Date::now(),
                    ]);
                if ($marked !== 1) {
                    throw new RuntimeException('The adoption decision lost its durable application marker.');
                }
                $decision->refresh();
            } elseif (! hash_equals($decision->application_fingerprint, $fingerprint)) {
                throw new ControlOperationRecoveryRequired(
                    'The external deploy key changed after the adoption decision began applying.',
                );
            }

            $intent = json_encode([
                'schema' => 1,
                'operation_id' => $operation->id,
                'request_hash' => $operation->request_hash,
                'attempt_token' => $attemptToken,
                'public_key_fingerprint' => $fingerprint,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $temporary = $active.'/'.self::INTENT.'.'.bin2hex(random_bytes(8)).'.tmp';
            try {
                if (file_put_contents($temporary, $intent."\n", LOCK_EX) === false
                    || ! rename($temporary, $active.'/'.self::INTENT)) {
                    throw new RuntimeException('The adopted deploy-key intent could not be published.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            DB::transaction(function () use ($operation, $decision, $attemptToken, $fingerprint): void {
                $updated = ControlOperation::query()
                    ->whereKey($operation->id)
                    ->where('current_attempt_token', $attemptToken)
                    ->where('state', ControlOperationState::RECOVERY_REQUIRED)
                    ->where('version', $operation->version)
                    ->where('finding_hash', $operation->finding_hash)
                    ->update([
                        'phase' => ControlOperationPhase::KEY_ACTIVATED,
                        'state' => ControlOperationState::RUNNING,
                        'effect_attempt_token' => $attemptToken,
                        'key_fingerprint' => $fingerprint,
                        'finding_text' => null,
                        'finding_hash' => null,
                        'recovery_attempt_token' => null,
                        'recovery_version' => null,
                        'recovery_effect_hash' => null,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => Date::now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The adoption decision lost its compare-and-swap binding.');
                }
                $this->markDecisionApplied($decision);
            });
        } finally {
            $lock->release();
        }
    }

    private function recoveryAttemptToken(ControlOperation $operation): int
    {
        $attemptToken = $operation->current_attempt_token;
        if ($attemptToken === null) {
            throw new RuntimeException('The recovery decision has no bound operation attempt.');
        }

        return $attemptToken;
    }

    private function markDecisionApplied(ControlOperationRecoveryDecision $decision): void
    {
        $updated = ControlOperationRecoveryDecision::query()
            ->whereKey($decision->id)
            ->where('state', 'pending')
            ->update([
                'state' => 'applied',
                'applied_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The recovery decision lost its single-consumption binding.');
        }
    }

    public function effectSnapshotHash(ControlOperation $operation, int $attemptToken): string
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'recovery effect inspection');

        try {
            return $this->effectSnapshotHashFor($this->currentEffectSnapshot($project));
        } finally {
            $lock->release();
        }
    }

    /** @return array{text: string, hash: string, effect_hash: string} */
    public function recoveryFinding(ControlOperation $operation, int $attemptToken, string $deviation): array
    {
        $project = $operation->project()->firstOrFail();
        $lock = $this->acquireEffectLock($operation, $project, $attemptToken, 'recovery finding inspection');

        try {
            $effect = $this->currentEffectSnapshot($project);
            $effectBytes = json_encode($effect, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $effectHash = $this->effectSnapshotHashFor($effect);
            $intent = [
                'request_hash' => $operation->request_hash,
                'effect_attempt_token' => $operation->effect_attempt_token,
                'launch_argument_hash' => $operation->launch_argument_hash,
                'public_key_fingerprint' => $operation->key_fingerprint,
            ];
            $intentBytes = json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $findingBytes = json_encode([
                'operation_id' => $operation->id,
                'effect_snapshot_hash' => $effectHash,
                'persisted_intent' => $intent,
                'deviation' => $deviation,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return [
                'text' => sprintf(
                    'Beobachteter Außenstand (SHA-256 %s): %s. Persistierter Intent: %s. Abweichung: %s',
                    $effectHash,
                    $effectBytes,
                    $intentBytes,
                    $deviation,
                ),
                'hash' => hash('sha256', "AI6-RECOVERY-FINDING-V1\0".$findingBytes),
                'effect_hash' => $effectHash,
            ];
        } finally {
            $lock->release();
        }
    }

    private function assertRecoveryEffectUnchanged(ControlOperation $operation, Project $project): void
    {
        if ($operation->recovery_effect_hash === null
            || ! hash_equals(
                $operation->recovery_effect_hash,
                $this->effectSnapshotHashFor($this->currentEffectSnapshot($project)),
            )) {
            throw new ControlOperationRecoveryRequired('The external deploy-key state changed after the recovery finding.');
        }
    }

    /** @return array<string, mixed> */
    private function currentEffectSnapshot(Project $project): array
    {
        $active = $this->paths->activeDirectory((string) $project->project_identifier);
        clearstatcache(true, $active);
        if (! file_exists($active) && ! is_link($active)) {
            return ['directory_type' => 'absent'];
        }

        $directoryType = is_link($active) ? 'link' : (is_dir($active) && realpath($active) === $active ? 'directory' : 'other');
        $snapshot = ['directory_type' => $directoryType];
        if ($directoryType !== 'directory') {
            return $snapshot;
        }

        foreach ([self::PRIVATE_KEY, self::PUBLIC_KEY, self::INTENT] as $name) {
            $path = $active.'/'.$name;
            clearstatcache(true, $path);
            if (! file_exists($path) && ! is_link($path)) {
                $snapshot[$name] = ['type' => 'absent'];

                continue;
            }

            $stat = @lstat($path);
            $regular = is_array($stat) && is_file($path) && ! is_link($path) && realpath($path) === $path;
            $snapshot[$name] = [
                'type' => is_link($path) ? 'link' : ($regular ? 'file' : 'other'),
                'mode' => is_array($stat) ? ($stat['mode'] & 0777) : null,
                'size' => is_array($stat) ? $stat['size'] : null,
                'sha256' => $regular && $name !== self::PRIVATE_KEY ? hash_file('sha256', $path) : null,
            ];
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function effectSnapshotHashFor(array $snapshot): string
    {
        return hash(
            'sha256',
            "AI6-DEPLOY-KEY-EFFECT-V1\0".json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /** @return list<string> */
    private function keygenArguments(ControlOperation $operation, string $privateKey): array
    {
        return [
            $this->processConfiguration->shellBinary,
            $this->configuration->sshKeygenWrapper,
            $this->configuration->sshKeygenBinary,
            '-C',
            'ai6:'.$operation->id.':'.$operation->request_hash,
            '-f',
            $privateKey,
        ];
    }

    private function acquireEffectLock(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        string $section,
    ): EffectLockHandle {
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new RuntimeException(sprintf('The operation lease was lost before %s.', $section));
        }

        $lock = $this->effectLock->acquire($this->lockNames->forProject((string) $project->project_identifier));
        if (! $lock->acquired() || $lock->handle === null) {
            $conflict = match ($lock->outcome) {
                EffectLockOutcome::UNAVAILABLE => 'effect_lock_unavailable',
                EffectLockOutcome::CONFLICT => 'effect_lock_conflict',
                EffectLockOutcome::CONFIGURATION_ERROR => 'effect_lock_configuration',
                EffectLockOutcome::ACQUIRED => 'effect_lock_invalid_result',
            };

            throw new ControlOperationRetryableConflict(
                $conflict,
                sprintf('The effect lock for %s is not safely available: %s', $section, $lock->message),
            );
        }
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            $lock->handle->release();

            throw new RuntimeException(sprintf('The operation lease was lost under the effect lock for %s.', $section));
        }

        return $lock->handle;
    }

    /** @param array<string, mixed> $updates */
    private function progress(ControlOperation $operation, int $attemptToken, ControlOperationPhase $phase, array $updates): void
    {
        if (! $this->lease->owns($operation->id, $operation->project_id, $attemptToken)) {
            throw new RuntimeException('The operation lease was lost before progress persistence.');
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
            throw new RuntimeException('Operation progress lost its compare-and-swap binding.');
        }

        $operation->refresh();
    }

    /** @return array{schema: int, operation_id: string, request_hash: string, attempt_token: int, public_key_fingerprint: string} */
    private function readIntent(string $directory): array
    {
        $path = $directory.'/'.self::INTENT;
        $this->paths->assertRegularFile($path, $directory);
        $content = file_get_contents($path);
        if (! is_string($content)) {
            throw new ControlOperationRecoveryRequired('The deploy-key intent could not be read.');
        }

        try {
            $intent = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ControlOperationRecoveryRequired('The deploy-key intent is malformed.', previous: $exception);
        }

        if (! is_array($intent)
            || ($intent['schema'] ?? null) !== 1
            || ! is_string($intent['operation_id'] ?? null)
            || ! is_string($intent['request_hash'] ?? null)
            || ! $this->isLowercaseSha256($intent['request_hash'])
            || ! is_int($intent['attempt_token'] ?? null)
            || ! is_string($intent['public_key_fingerprint'] ?? null)
            || ! $this->isLowercaseSha256($intent['public_key_fingerprint'])) {
            throw new ControlOperationRecoveryRequired('The deploy-key intent is incomplete.');
        }

        return $intent;
    }

    private function isLowercaseSha256(string $value): bool
    {
        return strlen($value) === 64
            && ctype_xdigit($value)
            && strtolower($value) === $value;
    }

    /** @param array{schema: int, operation_id: string, request_hash: string, attempt_token: int, public_key_fingerprint: string} $intent */
    private function intentMatches(array $intent, ControlOperation $operation): bool
    {
        return hash_equals($operation->id, $intent['operation_id'])
            && hash_equals($operation->request_hash, $intent['request_hash'])
            && $operation->effect_attempt_token === $intent['attempt_token']
            && $operation->key_fingerprint !== null
            && hash_equals($operation->key_fingerprint, $intent['public_key_fingerprint']);
    }

    private function assertGeneratedResultBinding(ControlOperation $operation, string $publicKeyPath): void
    {
        $publicKey = file_get_contents($publicKeyPath);
        if (! is_string($publicKey)) {
            throw new RuntimeException('The generated deploy-key result could not be read.');
        }
        $parts = preg_split('/\s+/', trim($publicKey), 3);
        $expectedComment = 'ai6:'.$operation->id.':'.$operation->request_hash;
        if (! is_array($parts) || count($parts) !== 3 || ! hash_equals($expectedComment, $parts[2])) {
            throw new RuntimeException('The generated deploy-key result has a foreign operation or request binding.');
        }
    }

    private function assertKeyPairMatches(
        ControlOperation $operation,
        string $privateKeyPath,
        string $publicKeyPath,
        string $workingDirectory,
    ): void {
        $this->paths->assertRegularFile($privateKeyPath, $workingDirectory);
        $this->paths->assertRegularFile($publicKeyPath, $workingDirectory);
        $publicKey = file_get_contents($publicKeyPath);
        if (! is_string($publicKey)) {
            throw new ControlOperationRecoveryRequired('The deploy-key public key could not be read for pair verification.');
        }
        $publicParts = preg_split('/\s+/', trim($publicKey));
        if (! is_array($publicParts) || count($publicParts) < 2) {
            throw new ControlOperationRecoveryRequired('The deploy-key public key is invalid for pair verification.');
        }

        $derived = $this->processes->run(new ProcessRequest(
            [$this->configuration->sshKeygenBinary, '-y', '-f', $privateKeyPath],
            $workingDirectory,
            [],
            [],
            new RedactionContext((string) $operation->project_id, $operation->id, 'deploy-key-pair-verification'),
        ));
        $derivedParts = preg_split('/\s+/', trim($derived->output));
        if (! $derived->succeeded()
            || ! is_array($derivedParts)
            || count($derivedParts) < 2
            || ! hash_equals($publicParts[0].' '.$publicParts[1], $derivedParts[0].' '.$derivedParts[1])) {
            throw new ControlOperationRecoveryRequired('The deploy-key private and public keys do not form a verified pair.');
        }
    }
}
