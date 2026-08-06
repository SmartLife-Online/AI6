<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\ProcessConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class ControlOperationCrashInjectionTest extends ControlOperationTestCase
{
    #[DataProvider('durablePhaseProvider')]
    public function test_reconciler_requeues_the_same_operation_after_a_crash_boundary(ControlOperationPhase $phase): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $operation->forceFill([
            'phase' => $phase,
            'state' => ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'effect_attempt_token' => in_array($phase, [ControlOperationPhase::KEY_GENERATED, ControlOperationPhase::KEY_ACTIVATED], true) ? 1 : null,
            'launch_argument_hash' => hash('sha256', 'fixture-arguments'),
            'process_id' => $phase === ControlOperationPhase::PROCESS_STARTED ? 1234 : null,
            'process_started_at' => $phase === ControlOperationPhase::PROCESS_STARTED ? now() : null,
        ])->save();
        $project->forceFill([
            'operation_lock_operation_id' => null,
            'operation_lock_lease_expires_at' => null,
            'operation_lock_heartbeat_at' => null,
        ])->save();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertStringContainsString($operation->id, (string) DB::table('jobs')->value('payload'));
        self::assertSame(ControlOperationState::RUNNING, $operation->refresh()->state);
    }

    /** @return iterable<string, array{ControlOperationPhase}> */
    public static function durablePhaseProvider(): iterable
    {
        foreach ([
            ControlOperationPhase::LAUNCH_INTENT,
            ControlOperationPhase::PROCESS_STARTED,
            ControlOperationPhase::KEY_GENERATED,
            ControlOperationPhase::KEY_ACTIVATED,
            ControlOperationPhase::PROVISIONING_FINALIZED,
        ] as $phase) {
            yield $phase->value => [$phase];
        }
    }

    #[DataProvider('allPhaseProvider')]
    public function test_real_worker_reconciles_every_declared_phase_to_one_external_and_terminal_result(
        ControlOperationPhase $phase,
    ): void {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();

        $this->placeAtPhase($operation, $project, $phase);
        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        (new ExecuteControlOperation($operation->id))->handle($this->app->make(ControlOperationExecutor::class));

        $operation->refresh();
        self::assertTrue($operation->state->terminal());
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertDirectoryExists($root.'/deploy-keys/'.$project->refresh()->project_identifier);
        self::assertFileExists($root.'/deploy-keys/'.$project->project_identifier.'/intent.json');
        self::assertNull($project->refresh()->operation_lock_operation_id);

        if ($phase === ControlOperationPhase::KEY_ACTIVATED) {
            $lease = $this->app->make(ProjectOperationLease::class);
            self::assertGreaterThan(1, $operation->current_attempt_token);
            self::assertFalse($lease->heartbeat($operation->id, $project->getKey(), 1));
            self::assertFalse($lease->release($operation->id, $project->getKey(), 1));
            self::assertSame(0, ControlOperation::query()
                ->whereKey($operation->id)
                ->where('current_attempt_token', 1)
                ->where('state', ControlOperationState::RUNNING)
                ->update(['phase' => ControlOperationPhase::ATTEMPT_COMPLETED->value]));
            self::assertSame(0, Project::query()
                ->whereKey($project->getKey())
                ->where('operation_lock_operation_id', $operation->id)
                ->where('operation_lock_attempt_token', 1)
                ->update(['public_deploy_key' => "ssh-ed25519 STALE stale-attempt\n"]));
        }
    }

    /** @return iterable<string, array{ControlOperationPhase}> */
    public static function allPhaseProvider(): iterable
    {
        foreach (ControlOperationPhase::cases() as $phase) {
            yield $phase->value => [$phase];
        }
    }

    private function placeAtPhase(ControlOperation $operation, Project $project, ControlOperationPhase $phase): void
    {
        if ($phase === ControlOperationPhase::QUEUED) {
            $operation->forceFill(['updated_at' => now()->subMinute()])->save();

            return;
        }

        $project->forceFill([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_attempt_token' => 1,
            'operation_lock_lease_expires_at' => now()->subSecond(),
            'operation_lock_heartbeat_at' => now()->subMinute(),
        ])->save();
        $launchHash = $this->launchHash($operation, $project, 1);

        if ($phase === ControlOperationPhase::RECOVERY_REQUIRED) {
            $project->forceFill(['operation_lock_lease_expires_at' => now()->addMinute()])->save();
            $operation->forceFill([
                'phase' => ControlOperationPhase::CLAIMED,
                'state' => ControlOperationState::RUNNING,
                'attempts' => 1,
                'current_attempt_token' => 1,
                'version' => 6,
            ])->save();
            $effectHash = $this->app->make(DeployKeyProvisioner::class)->effectSnapshotHash($operation, 1);
            $findingHash = hash('sha256', 'crash-recovery-finding');
            $operation->forceFill([
                'phase' => $phase,
                'state' => ControlOperationState::RECOVERY_REQUIRED,
                'finding_text' => 'Gebundener Crash-Recoverybefund.',
                'finding_hash' => $findingHash,
                'recovery_attempt_token' => 1,
                'recovery_version' => 7,
                'recovery_effect_hash' => $effectHash,
                'version' => 7,
            ])->save();
            $this->app->make(ProjectOperationLease::class)->expire($operation->id, $project->getKey(), 1);
            ControlOperationRecoveryDecision::query()->create([
                'id' => (string) Str::uuid(),
                'control_operation_id' => $operation->id,
                'actor_id' => $operation->actor_id,
                'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
                'expected_attempt_token' => 1,
                'expected_operation_version' => 7,
                'finding_hash' => $findingHash,
                'state' => 'pending',
            ]);

            return;
        }

        $fingerprint = null;
        if (in_array($phase, [
            ControlOperationPhase::KEY_GENERATED,
            ControlOperationPhase::KEY_ACTIVATED,
            ControlOperationPhase::PROVISIONING_FINALIZED,
            ControlOperationPhase::ATTEMPT_COMPLETED,
        ], true)) {
            $active = $phase !== ControlOperationPhase::KEY_GENERATED;
            $fingerprint = $this->writeBoundEffect($operation, $project, 1, $active);
        }

        if (in_array($phase, [ControlOperationPhase::PROVISIONING_FINALIZED, ControlOperationPhase::ATTEMPT_COMPLETED], true)) {
            $active = $this->app->make(ManagedProjectPath::class)->activeDirectory((string) $project->project_identifier);
            $project->forceFill([
                'deploy_key_reference' => $active.'/id_ed25519',
                'public_deploy_key' => rtrim((string) file_get_contents($active.'/id_ed25519.pub'))."\n",
                'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            ])->save();
        }

        if ($phase === ControlOperationPhase::ATTEMPT_COMPLETED) {
            ControlOperationResult::query()->create([
                'control_operation_id' => $operation->id,
                'outcome' => ControlOperationOutcome::SUCCEEDED,
                'result_binding' => hash('sha256', 'terminal-crash-result'),
                'safe_summary' => 'Bereits abgeschlossen.',
            ]);
        }

        $operation->forceFill([
            'phase' => $phase,
            'state' => $phase === ControlOperationPhase::ATTEMPT_COMPLETED
                ? ControlOperationState::COMPLETED
                : ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'effect_attempt_token' => in_array($phase, [
                ControlOperationPhase::LAUNCH_INTENT,
                ControlOperationPhase::PROCESS_STARTED,
                ControlOperationPhase::KEY_GENERATED,
                ControlOperationPhase::KEY_ACTIVATED,
                ControlOperationPhase::PROVISIONING_FINALIZED,
                ControlOperationPhase::ATTEMPT_COMPLETED,
            ], true) ? 1 : null,
            'launch_argument_hash' => in_array($phase, [ControlOperationPhase::QUEUED, ControlOperationPhase::CLAIMED], true)
                ? null
                : $launchHash,
            'process_id' => $phase === ControlOperationPhase::PROCESS_STARTED ? 1234 : null,
            'process_started_at' => $phase === ControlOperationPhase::PROCESS_STARTED ? now() : null,
            'key_fingerprint' => $fingerprint,
            'completed_at' => $phase === ControlOperationPhase::ATTEMPT_COMPLETED ? now() : null,
            'version' => 4,
        ])->save();
    }

    private function launchHash(ControlOperation $operation, Project $project, int $attemptToken): string
    {
        $paths = $this->app->make(ManagedProjectPath::class);
        $bundle = $paths->prepareBundle((string) $project->project_identifier, $operation->id, $attemptToken);
        $process = $this->app->make(ProcessConfiguration::class);
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $arguments = [
            $process->shellBinary,
            $configuration->sshKeygenWrapper,
            $configuration->sshKeygenBinary,
            '-C',
            'ai6:'.$operation->id.':'.$operation->request_hash,
            '-f',
            $bundle.'/id_ed25519',
        ];

        return hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function writeBoundEffect(
        ControlOperation $operation,
        Project $project,
        int $attemptToken,
        bool $active,
    ): string {
        $paths = $this->app->make(ManagedProjectPath::class);
        $directory = $active
            ? $paths->activeDirectory((string) $project->project_identifier)
            : $paths->prepareBundle((string) $project->project_identifier, $operation->id, $attemptToken);
        $this->generateTestKeyPair($directory, 'ai6:'.$operation->id.':'.$operation->request_hash);
        $fingerprint = hash_file('sha256', $directory.'/id_ed25519.pub');
        self::assertIsString($fingerprint);
        file_put_contents($directory.'/intent.json', json_encode([
            'schema' => 1,
            'operation_id' => $operation->id,
            'request_hash' => $operation->request_hash,
            'attempt_token' => $attemptToken,
            'public_key_fingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
        chmod($directory.'/intent.json', 0600);

        return $fingerprint;
    }
}
