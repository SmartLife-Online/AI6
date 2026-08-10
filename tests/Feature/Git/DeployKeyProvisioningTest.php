<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationRecoveryProcessor;
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
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
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
        $privateKey = (string) file_get_contents($root.'/deploy-keys/'.$project->project_identifier.'/id_ed25519');
        self::assertSame(0600, fileperms($root.'/deploy-keys/'.$project->project_identifier.'/id_ed25519') & 0777);
        self::assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $privateKey);
        $databaseSnapshot = json_encode([
            'projects' => DB::table('projects')->get()->all(),
            'operations' => DB::table('control_operations')->get()->all(),
            'results' => DB::table('control_operation_results')->get()->all(),
            'jobs' => DB::table('jobs')->get()->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($privateKey, $databaseSnapshot);
        self::assertStringNotContainsString('BEGIN OPENSSH PRIVATE KEY', $databaseSnapshot);
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

    public function test_real_worker_abandonment_of_an_already_provisioned_project_ends_as_a_named_conflict_without_regressing_it(): void
    {
        [$project, $operation] = $this->recoveryFixture(RecoveryDecisionType::ABANDON_OPERATION, withDecision: false);

        // Simulate the project having already been finalized as provisioned by a concurrent
        // path while this operation's own compare-and-swap identity (lock and provisioning
        // binding) still points at it.
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/other-attempt/id_ed25519',
            'public_deploy_key' => "ssh-ed25519 AAAAfinalized already-finalized\n",
        ])->save();

        ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $operation->actor_id,
            'decision' => RecoveryDecisionType::ABANDON_OPERATION,
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
            'reason' => 'Abbruch nach bereits abgeschlossener Provisionierung.',
            'bound_evidence' => 'Gebundener Testbefund.',
            'state' => 'pending',
        ]);

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->refresh()->state);
        self::assertSame('rejected', ControlOperationRecoveryDecision::query()->sole()->state);
        $project->refresh();
        self::assertSame(ProjectProvisioningStatus::PROVISIONED, $project->provisioning_status);
        self::assertSame('/managed/other-attempt/id_ed25519', $project->deploy_key_reference);
        self::assertSame(0, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
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
        self::assertSame(
            'Der Effekt-Lock ist für diese Operation derzeit nicht sicher verfügbar; sie wird erneut versucht.',
            $operation->last_error,
        );
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
        self::assertSame(
            'Der Effekt-Lock ist für diese Operation derzeit nicht sicher verfügbar; sie wird erneut versucht.',
            $operation->last_error,
        );
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
        // The contended contender below must never legitimately exhaust its own lock-wait
        // budget while blocked on the fixture holder; only the explicit SIGKILL below may end
        // the wait. Raising the budget well past any plausible scheduling delay removes the
        // flakiness the 500ms fixture default caused on a slow machine (finding: TC-22 budget).
        $this->raiseEffectLockWaitBudget(30_000);
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

    }

    public function test_managed_inventory_writes_are_repo_wide_routed_through_the_effect_lock(): void
    {
        $sharedInventoryMutationFiles = [];
        $terminalReleaseMethods = [];
        $root = base_path('app/AI6');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            if (str_contains($source, 'activeDirectory(')
                && preg_match('/\b(?:file_put_contents|rename|unlink|rmdir|chmod)\s*\(/', $source) === 1) {
                $sharedInventoryMutationFiles[] = $relative;
            }

            if (str_contains($source, 'releaseTerminal(')) {
                $terminalReleaseMethods[] = $relative;
            }
        }

        sort($sharedInventoryMutationFiles);
        sort($terminalReleaseMethods);
        self::assertSame([
            'app/AI6/Git/DeployKeyProvisioner.php',
            'app/AI6/Git/ManagedProjectPath.php',
        ], $sharedInventoryMutationFiles);
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:file_put_contents|rename|unlink|rmdir|chmod)\s*\(/',
            $this->methodSource(ManagedProjectPath::class, 'activeDirectory'),
            'The path resolver must remain effect-free; shared inventory writes belong in the locked provisioner.',
        );
        self::assertSame([
            'app/AI6/Git/ControlOperationExecutor.php',
            'app/AI6/Git/ControlOperationReconciler.php',
            'app/AI6/Git/DeployKeyProvisioner.php',
            'app/AI6/Git/ManagedCloneSynchronizer.php',
            'app/AI6/Git/ProjectOperationLease.php',
        ], $terminalReleaseMethods);

        foreach ([
            [ControlOperationExecutor::class, 'releaseTerminalLease'],
            [ControlOperationReconciler::class, 'releaseTerminalLeases'],
            [DeployKeyProvisioner::class, 'complete'],
            [ProjectOperationLease::class, 'releaseTerminal'],
        ] as [$class, $method]) {
            $releaseSource = $this->methodSource($class, $method);
            self::assertStringNotContainsString('$this->effectLock', $releaseSource, $class.'::'.$method.' conflates both locks.');
            self::assertStringNotContainsString('$lock->release()', $releaseSource, $class.'::'.$method.' conflates both locks.');
        }

        foreach (['activate', 'adoptExternalState'] as $method) {
            $methodSource = $this->methodSource(DeployKeyProvisioner::class, $method);
            self::assertStringContainsString('acquireEffectLock(', $methodSource, $method.' mutates shared inventory without the effect lock.');
            self::assertStringContainsString('$lock->release()', $methodSource, $method.' does not release its scoped effect lock.');
        }
        $activate = $this->methodSource(DeployKeyProvisioner::class, 'activate');
        self::assertLessThan(strpos($activate, 'rename($bundle, $active)'), strpos($activate, 'acquireEffectLock('));
        self::assertLessThan(strrpos($activate, '$lock->release()'), strpos($activate, 'rename($bundle, $active)'));
        $complete = $this->methodSource(DeployKeyProvisioner::class, 'complete');
        self::assertStringContainsString('cleanupFailedAttempt(', $complete);
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
        self::assertSame('Die Operation steht in Konflikt mit der aktiven Projektsperre.', $second->last_error);
        self::assertSame(ControlOperationOutcome::FAILED, $second->result()->sole()->outcome);
        self::assertSame($first->id, $project->refresh()->operation_lock_operation_id);
        self::assertNull($project->provisioning_operation_id);
        self::assertSame('provisioning_failed', $project->provisioning_status->value);
    }

    public function test_a_non_owning_attempts_claim_conflict_cannot_mutate_a_running_operation_it_does_not_own(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );

        $ownerToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('b', 32));
        self::assertIsInt($ownerToken);
        $operation->refresh();
        self::assertSame(ControlOperationState::RUNNING, $operation->state);
        self::assertNull($operation->last_error);
        $ownedVersion = $operation->version;
        $ownedAttemptToken = $operation->current_attempt_token;

        // A second, non-owning attempt tries to execute the same operation
        // while the first attempt's lease is still active. Its own claim
        // fails, so it must not be able to overwrite last_error or bump
        // version for the attempt that actually owns the lease.
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        self::assertSame(ControlOperationState::RUNNING, $operation->state);
        self::assertNull($operation->last_error);
        self::assertSame($ownedVersion, $operation->version);
        self::assertSame($ownedAttemptToken, $operation->current_attempt_token);
    }

    public function test_real_launch_contract_persists_process_identity_before_the_wrapper_is_released(): void
    {
        if (! function_exists('pcntl_fork') || ! is_file('/proc/self/stat')) {
            self::markTestSkipped('TC-21 requires Linux procfs and pcntl_fork.');
        }

        $this->useForkSafeDatabase();

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        // A deliberately slow keygen fixture widens the window between the wrapper being
        // released and the real key material appearing, so the launch-contract phase can be
        // observed reliably from the parent instead of racing a near-instant key generation.
        $this->installSlowKeygenFixture($root);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('f', 32)));

        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            DB::purge('sqlite');
            try {
                $this->app->make(DeployKeyProvisioner::class)->advance($operation->refresh(), 1);
                exit(0);
            } catch (\Throwable) {
                exit(51);
            }
        }

        DB::purge('sqlite');

        try {
            $observed = null;
            $deadline = microtime(true) + 5;
            do {
                $current = ControlOperation::query()->find($operation->id);
                if ($current instanceof ControlOperation
                    && $current->phase === ControlOperationPhase::PROCESS_STARTED
                    && $current->process_id !== null) {
                    $observed = $current;
                    break;
                }
                usleep(2_000);
            } while (microtime(true) < $deadline);

            self::assertNotNull($observed, 'The launch-contract phase was never observed as persisted.');
            self::assertSame(ControlOperationPhase::PROCESS_STARTED, $observed->phase);
            self::assertNotNull($observed->launch_argument_hash);
            self::assertNotNull($observed->process_id);
            self::assertNotNull($observed->process_started_at);
            self::assertTrue(
                is_file('/proc/'.$observed->process_id.'/stat'),
                'The persisted process identity does not name a running process.',
            );
            $effectAttemptId = $observed->effect_attempt_token;
            $stagingDirectory = $root.'/.control-staging/'.$operation->id;
            $stagedPrivateKeyPath = $stagingDirectory.'/'.$effectAttemptId.'/bundle/id_ed25519';
            self::assertFileDoesNotExist(
                $stagedPrivateKeyPath,
                'The staged private key must not exist while the process is still held before its effect.',
            );

            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if ($child > 0) {
                pcntl_waitpid($child, $status, WNOHANG);
            }
        }

        self::assertSame(ControlOperationPhase::KEY_GENERATED, $operation->refresh()->phase);

        // Static negative-probe proof: the code that persists the launch-contract fields must
        // textually precede the release call within generate(). A test double that released the
        // wrapper before this persistence would let the effect proceed with no persisted record
        // (see test_release_before_persistence_lets_a_blocked_effect_proceed_without_a_launch_record
        // below) — this assertion is what guarantees the real generate() path never does that.
        $generate = $this->methodSource(DeployKeyProvisioner::class, 'generate');
        self::assertLessThan(
            strpos($generate, '$blocked->release()'),
            strpos($generate, "'process_started_at' => Date::createFromTimestamp"),
            'DeployKeyProvisioner::generate() must persist the launch-contract fields before releasing the blocked wrapper.',
        );
    }

    public function test_release_before_persistence_lets_a_blocked_effect_proceed_without_a_launch_record(): void
    {
        if (! function_exists('pcntl_fork') || ! is_file('/proc/self/stat')) {
            self::markTestSkipped('TC-21 requires Linux procfs and pcntl_fork.');
        }

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );

        $marker = $root.'/tc21-negative-probe-effect';
        $start = $this->app->make(ControlProcessRunner::class)->startBlocked(
            new ProcessRequest(
                ['/usr/bin/touch', $marker],
                $root,
                [],
                [],
                new RedactionContext((string) $project->getKey(), $operation->id, 'tc21-negative-probe'),
            ),
            'lock-0001',
        );
        self::assertTrue($start->ready(), $start->message);
        self::assertNotNull($start->process);

        // The negative probe: release the blocked wrapper before any launch-contract record is
        // persisted — the reverse of the order DeployKeyProvisioner::generate() enforces. If the
        // real code ever did this, the process would proceed to its effect before anything was
        // written to the database.
        $start->process->release();
        $result = $start->process->wait();

        self::assertTrue($result->succeeded());
        self::assertFileExists(
            $marker,
            'A release-before-persistence ordering let the blocked effect proceed with no persisted launch '.
            'record — exactly the ordering the real generate() path must never allow.',
        );
        self::assertNull(
            $operation->refresh()->process_id,
            'No launch-contract record was ever persisted for this probe process; its effect happened independent of one.',
        );
    }

    public function test_real_reconciliation_finds_an_active_key_bound_to_another_operation_and_preserves_it_byte_identical(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        [$project, $first] = $this->completeRealProvisioning($administrator, $project);

        $active = $root.'/deploy-keys/'.$project->project_identifier;
        $privateBefore = file_get_contents($active.'/id_ed25519');
        $publicBefore = file_get_contents($active.'/id_ed25519.pub');
        $intentBefore = file_get_contents($active.'/intent.json');
        self::assertIsString($privateBefore);
        self::assertIsString($publicBefore);
        self::assertIsString($intentBefore);

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
            'request_hash' => hash('sha256', 'second-operation-reconciliation-request'),
            'phase' => ControlOperationPhase::QUEUED,
            'state' => ControlOperationState::QUEUED,
        ]);

        // Drive the second operation through the real claim/generate/activate path (not
        // forceFill): it stages its own genuine keypair and only then discovers, under the
        // effect lock, that the active key belongs to the first operation.
        $this->app->make(ControlOperationExecutor::class)->execute($second->id);

        $second->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $second->state);
        self::assertStringContainsString('nicht an diese Operation gebunden', (string) $second->last_error);
        self::assertSame($second->id, $project->refresh()->operation_lock_operation_id);

        self::assertSame($privateBefore, file_get_contents($active.'/id_ed25519'));
        self::assertSame($publicBefore, file_get_contents($active.'/id_ed25519.pub'));
        self::assertSame($intentBefore, file_get_contents($active.'/intent.json'));
        $intent = json_decode((string) file_get_contents($active.'/intent.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($first->id, $intent['operation_id']);
    }

    public function test_real_reconciliation_finds_an_active_key_with_no_attributable_intent_and_preserves_it_byte_identical(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $active = $this->app->make(ManagedProjectPath::class)
            ->activeDirectory((string) $project->project_identifier);
        $this->generateTestKeyPair($active, 'externally-planted-key-without-attributable-intent');
        // A syntactically parseable but schema-incomplete intent: it cannot be resolved to any
        // real operation, so this key must be neither adopted nor deleted by reconciliation.
        self::assertNotFalse(file_put_contents($active.'/intent.json', json_encode([
            'schema' => 1,
            'operation_id' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR)."\n"));
        chmod($active.'/intent.json', 0600);
        $privateBefore = file_get_contents($active.'/id_ed25519');
        $publicBefore = file_get_contents($active.'/id_ed25519.pub');
        $intentBefore = file_get_contents($active.'/intent.json');
        self::assertIsString($privateBefore);
        self::assertIsString($publicBefore);
        self::assertIsString($intentBefore);

        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );

        // Real claim/generate/activate path again: this operation stages its own keypair before
        // it ever inspects the active directory under the effect lock.
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertStringContainsString('Intent ist unvollständig', (string) $operation->last_error);
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);

        self::assertSame($privateBefore, file_get_contents($active.'/id_ed25519'));
        self::assertSame($publicBefore, file_get_contents($active.'/id_ed25519.pub'));
        self::assertSame($intentBefore, file_get_contents($active.'/intent.json'));
    }

    public function test_real_worker_cleans_a_staged_key_on_exhausted_retries_and_a_fresh_retry_produces_exactly_one_keypair(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        self::assertSame(3, $configuration->maxAttempts);

        // Pre-create the key root write-protected so the real, atomic activation rename fails
        // deterministically once a genuinely staged keypair exists, without touching staging.
        $keyRoot = $root.'/deploy-keys';
        self::assertTrue(mkdir($keyRoot, 0500, true));

        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $executor = $this->app->make(ControlOperationExecutor::class);

        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $executor->execute($operation->id);
                    self::fail('The activation unexpectedly succeeded while the key root was write-protected.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('remains retryable', $exception->getMessage());
                }
            }
            // The third delivery exhausts the configured retry budget and terminates the attempt.
            $executor->execute($operation->id);
        } finally {
            chmod($keyRoot, 0700);
        }

        $operation->refresh();
        $project->refresh();
        self::assertSame(3, $operation->attempts);
        self::assertSame(ControlOperationState::FAILED, $operation->state);
        self::assertSame('provisioning_failed', $project->provisioning_status->value);
        self::assertSame(ControlOperationOutcome::FAILED, $operation->result()->sole()->outcome);
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$operation->id);
        self::assertDirectoryDoesNotExist($keyRoot.'/'.$project->project_identifier);

        // A fresh retry after exhaustion produces exactly one keypair with no orphaned predecessor.
        $retry = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $executor->execute($retry->id);

        $retry->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $retry->state);
        self::assertSame('provisioned', $project->provisioning_status->value);
        $active = $keyRoot.'/'.$project->project_identifier;
        self::assertFileExists($active.'/id_ed25519');
        self::assertFileExists($active.'/id_ed25519.pub');
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$operation->id);
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$retry->id);
        $intent = json_decode((string) file_get_contents($active.'/intent.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($retry->id, $intent['operation_id']);
        $entries = array_values(array_diff(scandir($active), ['.', '..']));
        sort($entries);
        self::assertSame(['id_ed25519', 'id_ed25519.pub', 'intent.json'], $entries);
    }

    public function test_real_publish_aborts_before_any_effect_when_the_lease_is_lost_immediately_after_the_effect_lock_is_acquired(): void
    {
        if (! function_exists('pcntl_fork') || ! is_file('/proc/self/stat')) {
            self::markTestSkipped('TC-22 requires Linux procfs and pcntl_fork.');
        }

        $this->useForkSafeDatabase();

        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $this->raiseEffectLockWaitBudget(30_000);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('e', 32)));
        self::assertFalse($this->app->make(DeployKeyProvisioner::class)->advance($operation->refresh(), 1));
        self::assertSame(ControlOperationPhase::KEY_GENERATED, $operation->refresh()->phase);

        $holder = $this->startLockHoldingTarget($operation, $root);
        $active = $root.'/deploy-keys/'.$project->project_identifier;
        $exitFile = $root.'/tc22b-child-exit';
        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            DB::purge('sqlite');
            try {
                $this->app->make(DeployKeyProvisioner::class)->advance($operation->refresh(), 1);
                file_put_contents($exitFile, 'unexpected-success');
                exit(0);
            } catch (ControlOperationRetryableConflict $exception) {
                file_put_contents($exitFile, $exception->conflict.': '.$exception->getMessage());
                exit($exception->conflict === 'lease_lost' ? 0 : 61);
            } catch (\Throwable $exception) {
                file_put_contents($exitFile, get_class($exception).': '.$exception->getMessage());
                exit(62);
            }
        }

        DB::purge('sqlite');

        try {
            // The child's own pre-acquisition lease check has already passed by now (it runs in
            // microseconds right after the fork) and it is now blocked inside
            // EffectLock::acquire()'s poll loop because the fixture holder still owns the lock.
            // Expiring the lease here — strictly before the holder is released below — guarantees
            // that only the post-acquisition recheck (DeployKeyProvisioner::acquireEffectLock(),
            // the second `lease->owns()` check after `$this->effectLock->acquire(...)`) can catch
            // the loss, because the lock cannot be acquired before the holder is killed.
            usleep(50_000);
            self::assertTrue($lease->expire($operation->id, $project->getKey(), 1));
            (new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]))->mustRun();
            $holder->wait();

            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) file_get_contents($exitFile));
            self::assertDirectoryDoesNotExist($active);
        } finally {
            if (is_file('/proc/'.$holder->processId.'/stat')) {
                (new Process(['/usr/bin/kill', '-KILL', (string) $holder->processId]))->run();
            }
            if ($child > 0) {
                pcntl_waitpid($child, $status, WNOHANG);
            }
        }

        $acquireEffectLock = $this->methodSource(DeployKeyProvisioner::class, 'acquireEffectLock');
        self::assertSame(
            2,
            substr_count($acquireEffectLock, "'lease_lost',"),
            'acquireEffectLock() must recheck lease ownership both before and after acquiring the effect lock.',
        );
        self::assertLessThan(
            strrpos($acquireEffectLock, "'lease_lost',"),
            strpos($acquireEffectLock, '$this->effectLock->acquire('),
            'The post-acquisition lease recheck must occur after the effect lock has actually been acquired.',
        );
    }

    /** @return array{Project, ControlOperation} */
    private function completeRealProvisioning(User $administrator, Project $project): array
    {
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
        $operation->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);

        return [$project->refresh(), $operation];
    }

    private function installSlowKeygenFixture(string $root): string
    {
        $shim = $root.'/tc21-slow-ssh-keygen.sh';
        self::assertNotFalse(file_put_contents(
            $shim,
            "#!/bin/sh\nset -eu\nsleep 1\nexec /usr/bin/ssh-keygen \"\$@\"\n",
        ));
        chmod($shim, 0700);

        $current = $this->app->make(ControlOperationConfiguration::class);
        $slow = new ControlOperationConfiguration(
            $current->managedRoot,
            $current->keyRoot,
            $shim,
            $current->sshKeygenWrapper,
            $current->leaseSeconds,
            $current->heartbeatSeconds,
            $current->reconcilerSeconds,
            $current->maxAttempts,
            $current->knownHostsFile,
            $current->managedRefAllowlist,
            $current->staleSeconds,
            $current->reconciliationBudget,
            $current->refreshBasePath,
        );
        $this->app->instance(ControlOperationConfiguration::class, $slow);
        $this->app->instance(ManagedProjectPath::class, new ManagedProjectPath($slow));
        $this->app->instance(ProjectOperationLease::class, new ProjectOperationLease($slow));
        foreach ([DeployKeyProvisioner::class, ControlOperationRecoveryProcessor::class, ControlOperationExecutor::class] as $service) {
            $this->app->forgetInstance($service);
        }

        return $shim;
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

    /**
     * Rebinds the effect-lock wait budget to a much larger value than the fixture default
     * (500ms) so that a blocked-process race is not gated by wall-clock scheduling jitter on
     * a slow machine. The parent side still asserts "not yet effected" using a short sleep
     * while the fixture holder keeps the lock; only the deadline the contender itself waits
     * against needs headroom, because the holder is killed explicitly by the test, not by a
     * timeout.
     */
    private function raiseEffectLockWaitBudget(int $milliseconds): void
    {
        $current = $this->app->make(ProcessConfiguration::class);
        $raised = new ProcessConfiguration(
            $current->timeoutSeconds,
            $current->outputLimitBytes,
            $current->cancelGraceMilliseconds,
            $current->wrapperReadyTimeoutSeconds,
            $current->wrapperScript,
            $current->shellBinary,
            $current->setsidBinary,
            $current->processGroupKillBinary,
            $current->lockDirectory,
            $current->lockObjectCount,
            $milliseconds,
            $current->lockOwnerUid,
        );
        $effectLock = new EffectLock($raised);
        $this->app->instance(ProcessConfiguration::class, $raised);
        $this->app->instance(EffectLock::class, $effectLock);
        $this->app->instance(
            ControlProcessRunner::class,
            new ControlProcessRunner($raised, $this->app->make(Redactor::class), $effectLock),
        );
        foreach ([DeployKeyProvisioner::class, ControlOperationRecoveryProcessor::class, ControlOperationExecutor::class] as $service) {
            $this->app->forgetInstance($service);
        }
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
