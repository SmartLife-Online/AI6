<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\BlockedControlProcess;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DeployKeyProvisioningTest extends ControlOperationTestCase
{
    public function test_non_worker_runtime_cannot_execute_a_queued_control_operation(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        config(['ai6.runtime_role' => 'app']);

        try {
            $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
            self::fail('An app process executed a worker-only control operation.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('worker role', $exception->getMessage());
        }

        self::assertNull($operation->refresh()->process_id);
    }

    public function test_terminal_result_is_exactly_once_and_immutable(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $result = ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => hash('sha256', 'result'),
            'safe_summary' => 'Safe result.',
        ]);

        try {
            ControlOperationResult::query()->create([
                'control_operation_id' => $operation->id,
                'outcome' => ControlOperationOutcome::FAILED,
                'result_binding' => hash('sha256', 'duplicate'),
                'safe_summary' => 'Duplicate.',
            ]);
            self::fail('A second terminal result was accepted.');
        } catch (QueryException) {
            self::assertSame(1, ControlOperationResult::query()->count());
        }

        $this->expectException(QueryException::class);
        $result->forceFill(['safe_summary' => 'Changed.'])->save();
    }

    public function test_keygen_wrapper_has_only_fixed_arguments_and_no_dynamic_evaluation(): void
    {
        $script = file_get_contents(base_path('app/AI6/Git/generate-deploy-key.sh'));
        self::assertIsString($script);
        self::assertStringContainsString("exec \"\$keygen_binary\" -q -t ed25519 -N '' \"\$@\"", $script);
        self::assertStringNotContainsString('eval ', $script);
        self::assertStringNotContainsString('sh -c', $script);
    }

    public function test_generated_result_rejects_foreign_operation_and_request_bindings_before_persistence(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $path = tempnam(sys_get_temp_dir(), 'ai6-binding-');
        self::assertIsString($path);
        $method = new \ReflectionMethod(DeployKeyProvisioner::class, 'assertGeneratedResultBinding');

        try {
            foreach ([
                'ai6:'.Str::uuid().':'.$operation->request_hash,
                'ai6:'.$operation->id.':'.hash('sha256', 'foreign-request'),
            ] as $foreignComment) {
                self::assertNotFalse(file_put_contents($path, 'ssh-ed25519 AAAATEST '.$foreignComment."\n"));
                try {
                    $method->invoke($this->app->make(DeployKeyProvisioner::class), $operation, $path);
                    self::fail('A foreign generated-result binding was accepted.');
                } catch (\ReflectionException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    self::assertStringContainsString('foreign operation or request binding', $exception->getMessage());
                }
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_real_worker_executes_key_generation_activation_finalization_cleanup_and_exactly_once_result(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );

        $executor = $this->app->make(ControlOperationExecutor::class);
        $executor->execute($operation->id);
        $executor->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationOutcome::SUCCEEDED, $operation->result()->sole()->outcome);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertSame('provisioned', $project->provisioning_status->value);
        self::assertFileExists($root.'/deploy-keys/'.$project->project_identifier.'/id_ed25519');
        self::assertFileExists($root.'/deploy-keys/'.$project->project_identifier.'/id_ed25519.pub');
        self::assertFileExists($root.'/deploy-keys/'.$project->project_identifier.'/intent.json');
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$operation->id);
        $this->assertPersistedKeyPairCanSignAndVerify(
            $root.'/deploy-keys/'.$project->project_identifier,
            $root,
        );
    }

    public function test_real_worker_applies_retry_reconciliation(): void
    {
        [$project, $operation] = $this->recoveryFixture(RecoveryDecisionType::RETRY_RECONCILIATION);

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
        self::assertSame('provisioned', $project->refresh()->provisioning_status->value);
        self::assertSame('applied', ControlOperationRecoveryDecision::query()->sole()->state);
    }

    public function test_real_worker_applies_external_state_adoption(): void
    {
        [$project, $operation] = $this->recoveryFixture(RecoveryDecisionType::ADOPT_EXTERNAL_STATE, externalState: true);

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
        self::assertSame('provisioned', $project->refresh()->provisioning_status->value);
        self::assertSame('applied', ControlOperationRecoveryDecision::query()->sole()->state);
    }

    public function test_external_state_adoption_resumes_after_intent_publish_crash_under_a_new_attempt(): void
    {
        [$project, $operation] = $this->recoveryFixture(RecoveryDecisionType::ADOPT_EXTERNAL_STATE, externalState: true);
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(2, $lease->claim($operation, str_repeat('d', 32)));
        $operation->refresh();
        $active = $this->app->make(ManagedProjectPath::class)
            ->activeDirectory((string) $project->project_identifier);
        $fingerprint = hash_file('sha256', $active.'/id_ed25519.pub');
        self::assertIsString($fingerprint);
        $decision = ControlOperationRecoveryDecision::query()->sole();
        $decision->forceFill([
            'application_attempt_token' => 2,
            'application_fingerprint' => $fingerprint,
        ])->save();
        self::assertNotFalse(file_put_contents($active.'/intent.json', json_encode([
            'schema' => 1,
            'operation_id' => $operation->id,
            'request_hash' => $operation->request_hash,
            'attempt_token' => 2,
            'public_key_fingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"));
        self::assertTrue($lease->expire($operation->id, $project->getKey(), 2));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $decision->refresh();
        $intent = json_decode((string) file_get_contents($active.'/intent.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(3, $operation->current_attempt_token);
        self::assertSame('applied', $decision->state);
        self::assertSame(2, $decision->application_attempt_token);
        self::assertSame($fingerprint, $decision->application_fingerprint);
        self::assertSame(3, $intent['attempt_token']);
        self::assertSame($operation->request_hash, $intent['request_hash']);
    }

    public function test_real_worker_allows_a_second_abandonment_after_failed_adoption(): void
    {
        [$project, $operation] = $this->recoveryFixture(RecoveryDecisionType::ADOPT_EXTERNAL_STATE);
        $executor = $this->app->make(ControlOperationExecutor::class);

        $executor->execute($operation->id);
        $operation->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame('rejected', ControlOperationRecoveryDecision::query()->sole()->state);
        self::assertSame($operation->current_attempt_token, $operation->recovery_attempt_token);
        self::assertSame($operation->version, $operation->recovery_version);
        self::assertNotSame(hash('sha256', 'recovery-finding-adopt_external_state'), $operation->finding_hash);

        ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $operation->actor_id,
            'decision' => RecoveryDecisionType::ABANDON_OPERATION,
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
            'reason' => 'Sicherer Abbruch nach fehlgeschlagener Übernahme.',
            'bound_evidence' => 'Gebundener Testbefund.',
            'state' => 'pending',
        ]);
        $executor->execute($operation->id);

        self::assertSame(ControlOperationState::ABANDONED, $operation->refresh()->state);
        self::assertSame('provisioning_failed', $project->refresh()->provisioning_status->value);
        self::assertSame(ControlOperationOutcome::ABANDONED, $operation->result()->sole()->outcome);
        self::assertSame(['rejected', 'applied'], ControlOperationRecoveryDecision::query()->orderBy('created_at')->pluck('state')->all());
    }

    public function test_real_worker_keeps_an_unchanged_recovery_finding_and_decision_binding_stable(): void
    {
        [$project, $operation] = $this->recoveryFixture(
            RecoveryDecisionType::RETRY_RECONCILIATION,
            withDecision: false,
        );
        $oldFinding = $operation->finding_hash;
        $oldVersion = $operation->version;
        $oldAttempts = $operation->attempts;

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame(1, $operation->recovery_attempt_token);
        self::assertSame(7, $operation->recovery_version);
        self::assertSame($oldVersion, $operation->version);
        self::assertSame($oldAttempts, $operation->attempts);
        self::assertSame($oldFinding, $operation->finding_hash);
        self::assertSame($operation->id, $project->operation_lock_operation_id);
        self::assertNotNull($project->operation_lock_lease_expires_at);
        self::assertTrue($project->operation_lock_lease_expires_at->isPast());
        self::assertSame(0, ControlOperationResult::query()->count());
    }

    public function test_named_effect_lock_conflict_stays_visible_nonterminal_and_repeatable(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $held = $this->app->make(EffectLock::class)->acquire('lock-0001', 1);
        self::assertNotNull($held->handle);

        try {
            $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
            self::fail('A contended effect lock was not reported as a named retryable conflict.');
        } catch (ControlOperationRetryableConflict $exception) {
            self::assertStringStartsWith('effect_lock_', $exception->conflict);
        } finally {
            $held->handle->release();
        }

        $operation->refresh();
        self::assertSame(ControlOperationState::RUNNING, $operation->state);
        self::assertStringContainsString('effect-lock conflict', (string) $operation->last_error);
        self::assertNull($operation->result()->first());

        DB::table('jobs')->delete();
        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        self::assertSame(ControlOperationState::COMPLETED, $operation->refresh()->state);
    }

    public function test_unobservable_recovery_effect_preserves_the_last_real_finding_for_a_later_inspection(): void
    {
        [$project, $operation] = $this->recoveryFixture(
            RecoveryDecisionType::RETRY_RECONCILIATION,
            withDecision: false,
        );
        $findingHash = $operation->finding_hash;
        $effectHash = $operation->recovery_effect_hash;
        $version = $operation->version;
        $attempts = $operation->attempts;
        $held = $this->app->make(EffectLock::class)->acquire('lock-0001', 1);
        self::assertNotNull($held->handle);

        try {
            $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        } finally {
            $held->handle->release();
        }

        $operation->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame($findingHash, $operation->finding_hash);
        self::assertSame($effectHash, $operation->recovery_effect_hash);
        self::assertSame($version, $operation->version);
        self::assertSame($attempts, $operation->attempts);
        self::assertStringContainsString('effect lock', (string) $operation->last_error);
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
    }

    public function test_real_running_target_holds_the_effect_lock_across_lease_takeover_until_sigkill(): void
    {
        if (! function_exists('pcntl_fork') || ! is_file('/proc/self/stat')) {
            self::markTestSkipped('TC-18 requires Linux procfs and pcntl_fork.');
        }

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('b', 32)));
        $holder = $this->startLockHoldingTarget($operation, $root);
        $operation->forceFill([
            'phase' => ControlOperationPhase::PROCESS_STARTED,
            'effect_attempt_token' => 1,
            'launch_argument_hash' => hash('sha256', 'tc18-holder'),
            'process_id' => $holder->processId,
            'process_started_at' => now(),
        ])->save();
        DB::table('jobs')->delete();
        self::assertTrue($lease->expire($operation->id, $project->getKey(), 1));
        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(2, $lease->claim($operation->refresh(), str_repeat('c', 32)));

        $ready = $root.'/tc18-contender-ready';
        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            $start = $this->app->make(ControlProcessRunner::class)->startBlocked(
                new ProcessRequest(
                    ['/usr/bin/true'],
                    $root,
                    [],
                    [],
                    new RedactionContext((string) $project->getKey(), $operation->id, 'tc18-contender'),
                ),
                'lock-0001',
            );
            if (! $start->ready() || $start->process === null) {
                exit(21);
            }
            file_put_contents($ready, 'ready');
            $start->process->release();
            exit($start->process->wait()->succeeded() ? 0 : 22);
        }

        try {
            usleep(150_000);
            self::assertFileDoesNotExist($ready);
            self::assertSame('sleep', trim((string) file_get_contents('/proc/'.$holder->processId.'/comm')));
            self::assertSame('', trim((string) file_get_contents('/proc/'.$holder->processId.'/task/'.$holder->processId.'/children')));
            $kill = new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]);
            $kill->mustRun();
            $holder->wait();
            $this->waitForFile($ready);
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertFileDoesNotExist($root.'/deploy-keys/'.$project->project_identifier);
        } finally {
            if (is_file('/proc/'.$holder->processId.'/stat')) {
                (new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]))->run();
            }
            if ($child > 0) {
                pcntl_waitpid($child, $status, WNOHANG);
            }
        }
    }

    public function test_real_publish_waits_for_the_same_effect_lock_and_terminal_release_is_not_lock_release(): void
    {
        if (! function_exists('pcntl_fork') || ! is_file('/proc/self/stat')) {
            self::markTestSkipped('TC-22 requires Linux procfs and pcntl_fork.');
        }

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('d', 32)));
        self::assertFalse($this->app->make(DeployKeyProvisioner::class)->advance($operation->refresh(), 1));
        self::assertSame(ControlOperationPhase::KEY_GENERATED, $operation->refresh()->phase);
        $holder = $this->startLockHoldingTarget($operation, $root);
        $started = $root.'/tc22-publish-started';
        $published = $root.'/tc22-published';
        $active = $root.'/deploy-keys/'.$project->project_identifier;
        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            file_put_contents($started, 'started');
            try {
                $this->app->make(DeployKeyProvisioner::class)->advance($operation->refresh(), 1);
                file_put_contents($published, 'published');
                exit(0);
            } catch (\Throwable) {
                exit(31);
            }
        }

        try {
            $this->waitForFile($started);
            usleep(100_000);
            self::assertFileDoesNotExist($published);
            self::assertDirectoryDoesNotExist($active);
            (new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]))->mustRun();
            $holder->wait();
            $this->waitForFile($published);
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            self::assertDirectoryExists($active);
        } finally {
            if (is_file('/proc/'.$holder->processId.'/stat')) {
                (new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]))->run();
            }
            if ($child > 0) {
                pcntl_waitpid($child, $status, WNOHANG);
            }
        }

        $source = file_get_contents(base_path('app/AI6/Git/DeployKeyProvisioner.php'));
        self::assertIsString($source);
        $activate = $this->methodSource(DeployKeyProvisioner::class, 'activate');
        self::assertLessThan(strpos($activate, 'rename($bundle, $active)'), strpos($activate, 'acquireEffectLock('));
        self::assertLessThan(strrpos($activate, '$lock->release()'), strpos($activate, 'rename($bundle, $active)'));
        foreach (['activate', 'adoptExternalState'] as $method) {
            $methodSource = $this->methodSource(DeployKeyProvisioner::class, $method);
            self::assertStringContainsString('acquireEffectLock(', $methodSource);
            self::assertStringContainsString('$lock->release()', $methodSource);
        }
        $complete = $this->methodSource(DeployKeyProvisioner::class, 'complete');
        self::assertStringContainsString('releaseTerminal(', $complete);
        self::assertStringNotContainsString('$lock->release()', $complete);
    }

    public function test_second_operation_ends_as_named_project_lease_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $first = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $this->app->make(ProjectOperationLease::class)->claim($first, str_repeat('b', 32));
        $second = ControlOperation::query()->create([
            'id' => (string) Str::uuid(),
            'project_id' => $project->getKey(),
            'actor_id' => $administrator->getKey(),
            'operation_type' => $first->operation_type,
            'schema_version' => 1,
            'authorization_snapshot' => $first->authorization_snapshot,
            'authorization_snapshot_jcs' => $first->authorization_snapshot_jcs,
            'expected_control_commit' => $first->expected_control_commit,
            'operation_parameters_jcs' => $first->operation_parameters_jcs,
            'request_hash' => hash('sha256', 'second-operation-request'),
            'phase' => ControlOperationPhase::QUEUED,
            'state' => ControlOperationState::QUEUED,
        ]);
        $project->forceFill([
            'provisioning_operation_id' => $second->id,
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONING,
        ])->save();

        $this->app->make(ControlOperationExecutor::class)->execute($second->id);

        self::assertSame(ControlOperationState::FAILED, $second->refresh()->state);
        self::assertSame('Project operation lease conflict.', $second->last_error);
        self::assertSame(ControlOperationOutcome::FAILED, $second->result()->sole()->outcome);
        self::assertSame($first->id, $project->refresh()->operation_lock_operation_id);
        self::assertNull($project->provisioning_operation_id);
        self::assertSame('provisioning_failed', $project->provisioning_status->value);
    }

    /** @return array{Project, ControlOperation} */
    private function recoveryFixture(
        RecoveryDecisionType $decision,
        bool $externalState = false,
        bool $withDecision = true,
    ): array {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $operation->forceFill([
            'phase' => ControlOperationPhase::CLAIMED,
            'state' => ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'launch_argument_hash' => hash('sha256', 'recovery-launch'),
            'version' => 6,
        ])->save();
        $project->forceFill([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_attempt_token' => 1,
            'operation_lock_lease_expires_at' => now()->addMinute(),
            'operation_lock_heartbeat_at' => now(),
        ])->save();

        if ($externalState) {
            $active = $this->app->make(ManagedProjectPath::class)
                ->activeDirectory((string) $project->project_identifier);
            $this->generateTestKeyPair($active, 'external-adoption-fixture');
        }

        $deviation = 'Der aktive Deploy-Key weicht vom persistierten Operationsintent ab.';
        $finding = $this->app->make(DeployKeyProvisioner::class)->recoveryFinding($operation, 1, $deviation);
        $findingHash = $finding['hash'];
        $operation->forceFill([
            'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
            'state' => ControlOperationState::RECOVERY_REQUIRED,
            'finding_text' => $finding['text'],
            'finding_hash' => $findingHash,
            'recovery_attempt_token' => 1,
            'recovery_version' => 7,
            'recovery_effect_hash' => $finding['effect_hash'],
            'last_error' => $deviation,
            'version' => 7,
        ])->save();
        $this->app->make(ProjectOperationLease::class)->expire($operation->id, $project->getKey(), 1);
        if ($withDecision) {
            ControlOperationRecoveryDecision::query()->create([
                'id' => (string) Str::uuid(),
                'control_operation_id' => $operation->id,
                'actor_id' => $administrator->getKey(),
                'decision' => $decision,
                'expected_attempt_token' => 1,
                'expected_operation_version' => 7,
                'finding_hash' => $findingHash,
                'state' => 'pending',
            ]);
        }

        return [$project, $operation->refresh()];
    }

    private function assertPersistedKeyPairCanSignAndVerify(string $keyDirectory, string $proofRoot): void
    {
        $message = $proofRoot.'/tc20-message';
        $allowedSigners = $proofRoot.'/tc20-allowed-signers';
        self::assertNotFalse(file_put_contents($message, "AI6 TC-20 Signaturprobe\n"));
        $publicKey = file_get_contents($keyDirectory.'/id_ed25519.pub');
        self::assertIsString($publicKey);
        $parts = preg_split('/\s+/', trim($publicKey));
        self::assertIsArray($parts);
        self::assertGreaterThanOrEqual(2, count($parts));
        self::assertNotFalse(file_put_contents($allowedSigners, 'ai6-tc20 '.$parts[0].' '.$parts[1]."\n"));

        $sign = new Process([
            '/usr/bin/ssh-keygen', '-Y', 'sign',
            '-f', $keyDirectory.'/id_ed25519',
            '-n', 'ai6-tc20',
            $message,
        ]);
        $sign->setTimeout(30);
        $sign->mustRun();
        self::assertFileExists($message.'.sig');

        $verify = new Process([
            '/usr/bin/ssh-keygen', '-Y', 'verify',
            '-f', $allowedSigners,
            '-I', 'ai6-tc20',
            '-n', 'ai6-tc20',
            '-s', $message.'.sig',
        ]);
        $verify->setInput((string) file_get_contents($message));
        $verify->setTimeout(30);
        $verify->mustRun();
        self::assertStringContainsString('Good', $verify->getOutput().$verify->getErrorOutput());
    }

    private function startLockHoldingTarget(ControlOperation $operation, string $root): BlockedControlProcess
    {
        $start = $this->app->make(ControlProcessRunner::class)->startBlocked(
            new ProcessRequest(
                ['/usr/bin/sleep', '30'],
                $root,
                [],
                [],
                new RedactionContext((string) $operation->project_id, $operation->id, 'effect-lock-holder'),
            ),
            'lock-0001',
        );
        self::assertTrue($start->ready(), $start->message);
        self::assertNotNull($start->process);
        $start->process->release();
        $deadline = microtime(true) + 3;
        do {
            if (is_file('/proc/'.$start->process->processId.'/comm')
                && trim((string) file_get_contents('/proc/'.$start->process->processId.'/comm')) === 'sleep') {
                return $start->process;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        $start->process->cancel();
        self::fail('The lock holder did not exec into the target program.');
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 5;
        do {
            if (is_file($path)) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('The expected process marker was not created: '.$path);
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
