<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlBranchChanger;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlRemoteProbe;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\ControlBranchAuditEntry;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

final class ControlOperationCrashInjectionTest extends ControlOperationTestCase
{
    public function test_operation_specific_phase_sets_exhaust_the_declared_phase_enum(): void
    {
        $covered = [
            ...self::deployKeyPhases(),
            ControlOperationPhase::REMOTE_PROBED,
            ControlOperationPhase::EFFECT_STAGED,
            ControlOperationPhase::OUTCOME_PUBLISHED,
            ControlOperationPhase::BINDING_FINALIZED,
            ControlOperationPhase::PREPARED,
            ControlOperationPhase::COMMIT_PREPARED,
            ControlOperationPhase::CONTROL_CONFIRMED,
            ControlOperationPhase::DB_FINALIZED,
        ];
        $expected = array_map(
            static fn (ControlOperationPhase $phase): string => $phase->value,
            ControlOperationPhase::cases(),
        );
        $actual = array_map(
            static fn (ControlOperationPhase $phase): string => $phase->value,
            $covered,
        );
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    #[DataProvider('controlBranchCrashPhaseProvider')]
    public function test_control_branch_redelivery_is_exactly_once_at_every_phase_boundary(
        ControlOperationPhase $phase,
    ): void {
        config([
            'ai6.control_operations.managed_ref_allowlist' => 'refs/heads/main,refs/heads/next',
        ]);
        foreach ([ControlOperationConfiguration::class, ProjectOperationLease::class, ControlOperationReconciler::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $targetOid = str_repeat('b', 64);
        $probe = new CrashControlRemoteProbe($targetOid);
        $this->app->instance(ControlRemoteProbe::class, $probe);
        foreach ([ControlBranchChanger::class, ControlOperationExecutor::class] as $service) {
            $this->app->forgetInstance($service);
        }

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => str_repeat('a', 64),
            'control_binding_version' => 4,
            'control_generation' => 7,
        ])->save();
        $operation = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->branchStepUpRequest($administrator),
            $administrator,
            $project->refresh(),
            'refs/heads/next',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $lease = $this->app->make(ProjectOperationLease::class);
        $changer = $this->app->make(ControlBranchChanger::class);

        if ($phase === ControlOperationPhase::QUEUED) {
            $operation->forceFill([
                'updated_at' => now()->subSeconds(
                    $this->app->make(ControlOperationConfiguration::class)->reconcilerSeconds + 1,
                ),
            ])->save();
        } else {
            $token = $lease->claim($operation, 'control-branch-crash');
            self::assertIsInt($token);
            if (in_array($phase, [ControlOperationPhase::REMOTE_PROBED, ControlOperationPhase::ATTEMPT_COMPLETED], true)) {
                self::assertFalse($changer->advance($operation->refresh(), $token));
            }
            if ($phase === ControlOperationPhase::ATTEMPT_COMPLETED) {
                self::assertTrue($changer->advance($operation->refresh(), $token));
            } else {
                self::assertTrue($lease->expire($operation->id, $project->getKey(), $token));
            }
        }

        $reconciled = $this->app->make(ControlOperationReconciler::class)->reconcile();
        self::assertSame($phase === ControlOperationPhase::ATTEMPT_COMPLETED ? 0 : 1, $reconciled);
        if ($phase !== ControlOperationPhase::ATTEMPT_COMPLETED) {
            self::assertSame(1, DB::table('jobs')->count());
        }

        if ($phase !== ControlOperationPhase::ATTEMPT_COMPLETED) {
            $redeliveredToken = $lease->claim($operation->refresh(), 'control-branch-redelivered');
            self::assertIsInt($redeliveredToken);
            for ($step = 0; $step < 2 && ! $operation->refresh()->state->terminal(); $step++) {
                $changer->advance($operation, $redeliveredToken);
            }
        }
        self::assertTrue($changer->advance($operation->refresh(), (int) $operation->current_attempt_token));

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $operation->phase);
        self::assertSame(1, $probe->calls);
        self::assertSame(8, $project->control_generation);
        self::assertSame($targetOid, $project->pending_control_oid);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertSame(1, ControlBranchAuditEntry::query()->where('control_operation_id', $operation->id)->count());
        self::assertNull($project->operation_lock_operation_id);
    }

    /** @return iterable<string, array{ControlOperationPhase}> */
    public static function controlBranchCrashPhaseProvider(): iterable
    {
        yield 'queued' => [ControlOperationPhase::QUEUED];
        yield 'claimed' => [ControlOperationPhase::CLAIMED];
        yield 'remote probed' => [ControlOperationPhase::REMOTE_PROBED];
        yield 'attempt completed' => [ControlOperationPhase::ATTEMPT_COMPLETED];
    }

    /**
     * Partial, runtime-independent evidence for TC-07: durable nonterminal
     * phases are rediscovered and requeued. The terminal end-state and
     * exactly-once effect proof remains the gated real-worker test below.
     */
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
        foreach (self::deployKeyPhases() as $phase) {
            if (in_array($phase, [
                ControlOperationPhase::QUEUED, // Queue absence is covered by the queued-operation reconciler tests.
                ControlOperationPhase::CLAIMED, // No durable external-effect boundary has been crossed yet.
                ControlOperationPhase::ATTEMPT_COMPLETED, // Terminal operations are released, never requeued.
                ControlOperationPhase::RECOVERY_REQUIRED, // Recovery has its own nonterminal decision contract.
            ], true)) {
                continue;
            }

            yield $phase->value => [$phase];
        }
    }

    /**
     * TC-16: an attempt that is paused before its child process ever starts is
     * resumed after its lease expires and the reconciler redelivers the operation
     * under a higher attempt token. Every effect of the paused attempt -- heartbeat,
     * phase transition, process start, result persistence, lock release and the
     * terminal publish -- is rejected as a fencing conflict through the real
     * `ProjectOperationLease` and `DeployKeyProvisioner` production paths. The new
     * attempt alone remains effective and reaches exactly one terminal state.
     */
    public function test_tc16_a_stale_attempt_paused_before_process_start_is_fenced_on_every_effect(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();

        $lease = $this->app->make(ProjectOperationLease::class);
        $deployKeys = $this->app->make(DeployKeyProvisioner::class);
        $paths = $this->app->make(ManagedProjectPath::class);
        $configuration = $this->app->make(ControlOperationConfiguration::class);

        // The first attempt claims the project lease and is paused before it ever
        // reaches the process runner: `advance()`/`generate()` are never invoked for
        // it, so no child process comes into existence.
        $firstAttemptToken = $lease->claim($operation->refresh(), 'boot-attempt-one');
        self::assertSame(1, $firstAttemptToken);
        self::assertSame(ControlOperationPhase::CLAIMED, $operation->refresh()->phase);

        // The lease expires purely through the passage of time: the paused attempt
        // never sent a heartbeat because it never reached the loop that sends one.
        $this->travel($configuration->leaseSeconds + 1)->seconds();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertStringContainsString($operation->id, (string) DB::table('jobs')->value('payload'));

        // The redelivered worker claims the lease under a higher attempt token while
        // the operation is still sitting at `claimed` -- exactly the state the paused
        // first attempt still believes it is in.
        $secondAttemptToken = $lease->claim($operation->refresh(), 'boot-attempt-two');
        self::assertSame(2, $secondAttemptToken);
        self::assertSame(ControlOperationPhase::CLAIMED, $operation->refresh()->phase);

        // Effect 1/6 -- heartbeat: the paused attempt can no longer keep its lease alive.
        self::assertFalse($lease->heartbeat($operation->id, $project->getKey(), $firstAttemptToken));

        // Effect 2/6 and 3/6 -- phase transition and process start: resuming the
        // paused attempt through the real `advance()`/`generate()` production path is
        // rejected before any process is launched.
        try {
            $deployKeys->advance($operation, $firstAttemptToken);
            self::fail('The paused attempt was able to progress after a lease takeover.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lease was lost', $exception->getMessage());
        }
        $operation->refresh();
        self::assertSame(ControlOperationPhase::CLAIMED, $operation->phase);
        self::assertNull($operation->launch_argument_hash);
        $staleBundle = $paths->prepareBundle((string) $project->project_identifier, $operation->id, $firstAttemptToken);
        self::assertFileDoesNotExist($staleBundle.'/id_ed25519');

        // Drive the winning attempt for real, one phase at a time, so the losing
        // attempt can be probed again immediately before the terminal write.
        self::assertFalse($deployKeys->advance($operation, $secondAttemptToken));
        self::assertSame(ControlOperationPhase::KEY_GENERATED, $operation->refresh()->phase);

        self::assertFalse($deployKeys->advance($operation, $secondAttemptToken));
        self::assertSame(ControlOperationPhase::KEY_ACTIVATED, $operation->refresh()->phase);

        self::assertFalse($deployKeys->advance($operation, $secondAttemptToken));
        self::assertSame(ControlOperationPhase::PROVISIONING_FINALIZED, $operation->refresh()->phase);

        // Effect 4/6 and 6/6 -- result persistence and publish: the paused attempt
        // cannot finalize the saga either, even though the live phase now matches
        // the phase it would need to dispatch into `complete()`.
        self::assertSame(0, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        try {
            $deployKeys->advance($operation, $firstAttemptToken);
            self::fail('The paused attempt was able to publish the terminal result after a lease takeover.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lease was lost', $exception->getMessage());
        }
        $operation->refresh();
        self::assertSame(ControlOperationPhase::PROVISIONING_FINALIZED, $operation->phase);
        self::assertSame(ControlOperationState::RUNNING, $operation->state);
        self::assertSame(0, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());

        // The new attempt remains solely effective and reaches exactly one terminal state.
        self::assertTrue($deployKeys->advance($operation, $secondAttemptToken));
        $operation->refresh();
        self::assertTrue($operation->state->terminal());
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $operation->phase);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertDirectoryExists($root.'/deploy-keys/'.$project->refresh()->project_identifier);
        self::assertNull($project->refresh()->operation_lock_operation_id);

        // Effect 5/6 -- lock release: the paused attempt can never release a lock it
        // does not hold, whether before or after the winning attempt terminates it.
        self::assertFalse($lease->release($operation->id, $project->getKey(), $firstAttemptToken));
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
            $deployKeys = $this->app->make(DeployKeyProvisioner::class);
            self::assertGreaterThan(1, $operation->current_attempt_token);
            self::assertFalse($lease->heartbeat($operation->id, $project->getKey(), 1));
            self::assertFalse($lease->release($operation->id, $project->getKey(), 1));

            // TC-27: waking the old, fenced attempt is driven through real production
            // entry points instead of a raw-query fixture write. `cleanupFailedAttempt()`
            // is reachable independently of the operation's current phase and still
            // rejects the stale attempt token as a fencing conflict.
            try {
                $deployKeys->cleanupFailedAttempt($operation, 1);
                self::fail('The stale attempt was able to run cleanup after a lease takeover.');
            } catch (ControlOperationRetryableConflict $exception) {
                self::assertSame('lease_lost', $exception->conflict);
            }

            // The winning attempt must adopt the same operation's active effect even
            // though its intent belongs to the fenced predecessor. Restricting
            // adoption to the current attempt token would strand this operation in
            // recovery and violate AC-25.
            $active = $this->app->make(ManagedProjectPath::class)
                ->activeDirectory((string) $project->refresh()->project_identifier);
            $intent = json_decode((string) file_get_contents($active.'/intent.json'), true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(1, $intent['attempt_token']);
            self::assertGreaterThan($intent['attempt_token'], $operation->refresh()->current_attempt_token);
            self::assertTrue($operation->state->terminal());
        }
    }

    /** @return iterable<string, array{ControlOperationPhase}> */
    public static function allPhaseProvider(): iterable
    {
        foreach (self::deployKeyPhases() as $phase) {
            yield $phase->value => [$phase];
        }
    }

    /** @return list<ControlOperationPhase> */
    private static function deployKeyPhases(): array
    {
        return [
            ControlOperationPhase::QUEUED,
            ControlOperationPhase::CLAIMED,
            ControlOperationPhase::LAUNCH_INTENT,
            ControlOperationPhase::PROCESS_STARTED,
            ControlOperationPhase::KEY_GENERATED,
            ControlOperationPhase::KEY_ACTIVATED,
            ControlOperationPhase::PROVISIONING_FINALIZED,
            ControlOperationPhase::RECOVERY_REQUIRED,
            ControlOperationPhase::ATTEMPT_COMPLETED,
        ];
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
        $arguments = $this->productionKeygenArguments($operation, $bundle.'/id_ed25519');

        return hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Invokes the real, private `DeployKeyProvisioner::keygenArguments()` production
     * method through reflection instead of hand-copying its argument list. Binding to
     * the actual production function means any future change to the launch-argument
     * shape or the key-generation comment format fails this fixture instead of
     * silently drifting from what the worker really executes.
     *
     * @return list<string>
     */
    private function productionKeygenArguments(ControlOperation $operation, string $privateKey): array
    {
        $provisioner = $this->app->make(DeployKeyProvisioner::class);
        $method = new ReflectionMethod($provisioner, 'keygenArguments');
        $method->setAccessible(true);

        /** @var list<string> $arguments */
        $arguments = $method->invoke($provisioner, $operation, $privateKey);

        return $arguments;
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
        $comment = $this->productionKeygenArguments($operation, $directory.'/id_ed25519')[4];
        $this->generateTestKeyPair($directory, $comment);
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

    private function branchStepUpRequest(User $administrator): Request
    {
        $session = new Store('control-branch-crash', new ArraySessionHandler(120));
        $session->setId('control-branch-crash-'.bin2hex(random_bytes(8)));
        $session->start();
        $request = Request::create('/projects/control-branch', 'POST');
        $request->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $request,
            $administrator,
            QueueControlBranchChange::STEP_UP_ACTION,
        );

        return $request;
    }
}

final class CrashControlRemoteProbe implements ControlRemoteProbe
{
    public int $calls = 0;

    public function __construct(private readonly string $targetOid) {}

    public function resolve(Project $project, string $ref, RedactionContext $context): string
    {
        $this->calls++;

        return $this->targetOid;
    }
}
