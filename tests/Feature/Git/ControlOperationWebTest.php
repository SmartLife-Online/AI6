<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationRecoveryProcessor;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;

final class ControlOperationWebTest extends ControlOperationTestCase
{
    public function test_global_project_administrator_can_enqueue_without_executing_a_process_in_the_request(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);

        $response = $this->actingAs($administrator)->post(route('projects.deploy-key.provision', $project), [
            'operation_id' => (string) Str::uuid(),
        ]);

        $operation = ControlOperation::query()->sole();
        $response->assertRedirect(route('projects.operations.show', [$project, $operation]));
        self::assertNull($operation->process_id);
        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee($operation->id)
            ->assertSee('queued');
    }

    public function test_viewer_and_non_member_are_denied_without_operation_content(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $outsider = $this->createUser();
        $project = $this->registeredProject($administrator);
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);

        foreach ([$viewer, $outsider] as $user) {
            $this->actingAs($user)
                ->post(route('projects.deploy-key.provision', $project), ['operation_id' => (string) Str::uuid()])
                ->assertForbidden()
                ->assertDontSee($project->name);
        }

        self::assertSame(0, ControlOperation::query()->count());
    }

    public function test_operation_failure_is_redacted_by_the_executor_before_persistence_and_rendering(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();

        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $singleAttempt = new ControlOperationConfiguration(
            $configuration->managedRoot,
            $configuration->keyRoot,
            $configuration->sshKeygenBinary,
            $configuration->sshKeygenWrapper,
            $configuration->leaseSeconds,
            $configuration->heartbeatSeconds,
            $configuration->reconcilerSeconds,
            1,
            $configuration->knownHostsFile,
            $configuration->managedRefAllowlist,
            $configuration->staleSeconds,
            $configuration->reconciliationBudget,
            $configuration->refreshBasePath,
        );
        $this->app->instance(ControlOperationConfiguration::class, $singleAttempt);
        $this->app->instance(ManagedProjectPath::class, new ManagedProjectPath($singleAttempt));
        foreach ([DeployKeyProvisioner::class, ControlOperationRecoveryProcessor::class, ControlOperationExecutor::class] as $service) {
            $this->app->forgetInstance($service);
        }

        $credential = 'hunter2';
        $keyContent = 'AAAAB3NzaC1lZDI1NTE5AAAAITestPrivateKeyBytes';
        $rawFailure = 'https://alice:'.$credential.'@git.example.test password='.$credential.' api_key='.$keyContent;
        $failureInjected = false;
        DB::listen(static function (QueryExecuted $query) use (&$failureInjected, $rawFailure): void {
            if (! $failureInjected && str_contains(strtolower($query->sql), 'from "users"')) {
                $failureInjected = true;

                throw new RuntimeException($rawFailure);
            }
        });

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        self::assertTrue($failureInjected);
        $operation->refresh();
        self::assertSame(ControlOperationOutcome::FAILED, $operation->result()->sole()->outcome);
        self::assertStringContainsString('[REDACTED:CREDENTIAL]', (string) $operation->last_error);
        self::assertStringContainsString('[REDACTED:SECRET]', (string) $operation->last_error);
        self::assertStringNotContainsString($credential, (string) $operation->last_error);
        self::assertStringNotContainsString($keyContent, (string) $operation->last_error);

        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee('[REDACTED:CREDENTIAL]')
            ->assertSee('[REDACTED:SECRET]')
            ->assertDontSee($credential)
            ->assertDontSee($keyContent);
    }

    public function test_named_retryable_conflicts_persist_fixed_german_summaries_on_both_nonterminal_paths(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();

        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('a', 32)));
        $executor = $this->app->make(ControlOperationExecutor::class);
        $recordRetryableConflict = new ReflectionMethod($executor, 'recordRetryableConflict');

        try {
            $recordRetryableConflict->invoke(
                $executor,
                $operation->refresh(),
                1,
                new ControlOperationRetryableConflict(
                    'lease_lost',
                    'The operation lease was lost before deploy-key activation.',
                ),
            );
            self::fail('The retryable-conflict path did not propagate its stable conflict slug.');
        } catch (ControlOperationRetryableConflict $exception) {
            self::assertSame('lease_lost', $exception->conflict);
        }

        $leaseSummary = 'Die Operation hat ihre Projektsperre verloren und wird erneut versucht.';
        self::assertSame($leaseSummary, $operation->refresh()->last_error);
        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee($leaseSummary)
            ->assertDontSee('The operation lease was lost');

        self::assertSame(2, $lease->claim($operation->refresh(), str_repeat('b', 32)));
        $recordFailure = new ReflectionMethod($executor, 'recordFailure');
        try {
            $recordFailure->invoke(
                $executor,
                $operation->refresh(),
                2,
                new ControlOperationRetryableConflict(
                    'effect_lock_conflict',
                    'The effect lock for deploy-key activation is not safely available: internal detail.',
                ),
            );
            self::fail('The nonterminal failure path did not remain retryable.');
        } catch (RuntimeException $exception) {
            self::assertSame('The control operation attempt failed and remains retryable.', $exception->getMessage());
        }

        $effectLockSummary = 'Der Effekt-Lock ist für diese Operation derzeit nicht sicher verfügbar; sie wird erneut versucht.';
        self::assertSame($effectLockSummary, $operation->refresh()->last_error);
        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee($effectLockSummary)
            ->assertDontSee('The effect lock for deploy-key activation')
            ->assertDontSee('internal detail');
    }
}
