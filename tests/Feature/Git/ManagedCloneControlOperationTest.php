<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;

final class ManagedCloneControlOperationTest extends ControlOperationTestCase
{
    public function test_clone_and_fetch_are_queued_as_typed_asynchronous_operations(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/var/lib/ai6/managed/deploy-keys/'.str_repeat('a', 32).'/id_ed25519',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();

        $cloneId = (string) Str::uuid();
        $response = $this->actingAs($administrator)->post(route('projects.managed-clone.clone', $project), [
            'operation_id' => $cloneId,
        ]);

        $clone = ControlOperation::query()->findOrFail($cloneId);
        $response->assertRedirect(route('projects.operations.show', [$project, $clone]));
        self::assertSame(ControlOperationType::MANAGED_CLONE, $clone->operation_type);
        self::assertSame(ControlOperationState::QUEUED, $clone->state);
        self::assertSame(ControlOperationPhase::QUEUED, $clone->phase);
        self::assertNull($clone->process_id);
        self::assertNull($clone->target_control_oid);
        self::assertSame(
            ['control_ref' => 'refs/heads/main', 'expected_binding_version' => 0],
            json_decode($clone->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR),
        );

        $project->refresh();
        self::assertSame($cloneId, $project->operation_lock_operation_id);
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Managed-Clone erstellen')
            ->assertSee('Noch nicht gebunden');

        $project->forceFill([
            'operation_lock_operation_id' => null,
            'operation_lock_lease_expires_at' => null,
            'operation_lock_heartbeat_at' => null,
            'control_oid' => str_repeat('b', 64),
            'control_binding_version' => 1,
        ])->save();
        $fetchId = (string) Str::uuid();
        $this->actingAs($administrator)->post(route('projects.managed-clone.fetch', $project), [
            'operation_id' => $fetchId,
        ])->assertRedirect();

        $fetch = ControlOperation::query()->findOrFail($fetchId);
        self::assertSame(ControlOperationType::MANAGED_FETCH, $fetch->operation_type);
        self::assertSame(str_repeat('b', 64), $fetch->expected_control_commit);
        self::assertSame(
            [
                'control_ref' => 'refs/heads/main',
                'expected_binding_version' => 1,
                'pending_binding_version' => null,
                'pending_control_oid' => null,
                'pending_source_operation_id' => null,
            ],
            json_decode($fetch->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($administrator)->post(route('projects.managed-clone.fetch', $project), [
            'operation_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('operation_id');
        self::assertSame(2, ControlOperation::query()->count());
    }

    public function test_managed_clone_routes_require_the_global_project_administrator(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();

        $this->actingAs($viewer)
            ->post(route('projects.managed-clone.clone', $project), ['operation_id' => (string) Str::uuid()])
            ->assertForbidden();
        self::assertSame(0, ControlOperation::query()->count());

    }

    public function test_competing_fetch_enqueues_create_exactly_one_operation_and_database_job(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => str_repeat('a', 64),
            'control_binding_version' => 1,
        ])->save();
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $action = $this->app->make(QueueManagedCloneOperation::class);

        $first = $action->handle(
            $administrator,
            $project,
            ControlOperationType::MANAGED_FETCH,
            $firstId,
        );

        try {
            $action->handle(
                $administrator,
                $project,
                ControlOperationType::MANAGED_FETCH,
                $secondId,
            );
            self::fail('A competing managed fetch acquired the already claimed project lease.');
        } catch (ControlOperationConflict $exception) {
            self::assertSame('Another mutating project operation is active.', $exception->getMessage());
        }

        self::assertSame($firstId, $first->id);
        self::assertSame(1, ControlOperation::query()
            ->where('project_id', $project->getKey())
            ->where('operation_type', ControlOperationType::MANAGED_FETCH)
            ->count());
        self::assertSame(1, DB::table('jobs')->count());
        $payload = (string) DB::table('jobs')->value('payload');
        self::assertStringContainsString($firstId, $payload);
        self::assertStringNotContainsString($secondId, $payload);
    }

    public function test_project_view_shows_the_latest_managed_synchronization_binding_age_and_outcome(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();
        $completedAt = now()->startOfSecond();
        $targetOid = str_repeat('b', 64);
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        self::assertSame(
            1,
            $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32)),
        );
        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::LAUNCH_INTENT->value,
            'state' => ControlOperationState::RUNNING->value,
            'effect_attempt_token' => $operation->current_attempt_token,
            'target_control_oid' => $targetOid,
            'launch_argument_hash' => str_repeat('c', 64),
            'version' => DB::raw('version + 1'),
        ]);
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => ControlOperationOutcome::SUCCEEDED,
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Der Managed-Clone wurde erstellt und gebunden.',
        ]);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED->value,
            'state' => ControlOperationState::COMPLETED->value,
            'completed_at' => $completedAt,
            'version' => DB::raw('version + 1'),
        ]);
        DB::table('projects')->where('id', $project->getKey())->update([
            'control_oid' => $targetOid,
            'control_binding_version' => 1,
            'operation_lock_operation_id' => null,
            'operation_lock_lease_expires_at' => null,
            'operation_lock_heartbeat_at' => null,
        ]);

        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee($targetOid)
            ->assertSee($completedAt->toIso8601String())
            ->assertSee('Aktuell')
            ->assertSee('Letzte Managed-Clone-Synchronisierung')
            ->assertSee('completed / attempt_completed')
            ->assertSee('Ergebnis: succeeded')
            ->assertSee('Der Managed-Clone wurde erstellt und gebunden.');

        $this->travel(1)->seconds();
        $failed = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $secret = 'private-value';
        $safeError = $this->app->make(Redactor::class)->redact(
            'password='.$secret,
            new RedactionContext((string) $project->getKey(), $failed->id, 'managed-fetch-failure'),
        )->text;
        ControlOperationResult::query()->create([
            'control_operation_id' => $failed->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => str_repeat('e', 64),
            'safe_summary' => 'Die Managed-Clone-Synchronisierung ist fehlgeschlagen.',
        ]);
        DB::table('control_operations')->where('id', $failed->id)->update([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED->value,
            'state' => ControlOperationState::FAILED->value,
            'last_error' => $safeError,
            'completed_at' => now(),
            'version' => DB::raw('version + 1'),
        ]);
        $project->forceFill([
            'operation_lock_operation_id' => null,
            'operation_lock_lease_expires_at' => null,
            'operation_lock_heartbeat_at' => null,
        ])->save();

        // Even the redacted internal diagnostic never becomes response
        // content: both project and operation views show only the generic
        // failure state and the bound safe summary.
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('failed / attempt_completed')
            ->assertSee('Der letzte Versuch meldete einen intern protokollierten Fehler.')
            ->assertDontSee($safeError)
            ->assertDontSee($secret)
            ->assertSee($completedAt->toIso8601String());
        $this->actingAs($administrator)
            ->get(route('projects.operations.show', [$project, $failed]))
            ->assertOk()
            ->assertSee('Der letzte Versuch meldete einen intern protokollierten Fehler.')
            ->assertSee('Die Managed-Clone-Synchronisierung ist fehlgeschlagen.')
            ->assertDontSee($safeError)
            ->assertDontSee($secret);

        $this->travel(300)->seconds();
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('Veraltet');
    }

    public function test_database_contract_keeps_target_oid_immutable_within_an_attempt_and_rebinds_it_atomically_for_a_new_attempt(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32));
        self::assertSame(1, $token);

        try {
            DB::table('control_operations')->where('id', $operation->id)->update([
                'target_control_oid' => str_repeat('b', 64),
            ]);
            self::fail('A half-bound managed-clone intent was accepted.');
        } catch (QueryException) {
            self::assertNull($operation->refresh()->target_control_oid);
        }

        try {
            DB::table('control_operations')->where('id', $operation->id)->update([
                'phase' => ControlOperationPhase::EFFECT_STAGED->value,
                'launch_argument_hash' => str_repeat('c', 64),
                'version' => DB::raw('version + 1'),
            ]);
            self::fail('A managed effect phase without a previously bound intent was accepted.');
        } catch (QueryException) {
            self::assertSame(ControlOperationPhase::CLAIMED, $operation->refresh()->phase);
        }

        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::LAUNCH_INTENT->value,
            'effect_attempt_token' => 1,
            'target_control_oid' => str_repeat('b', 64),
            'launch_argument_hash' => str_repeat('c', 64),
            'version' => DB::raw('version + 1'),
        ]);
        self::assertSame(str_repeat('b', 64), $operation->refresh()->target_control_oid);

        try {
            DB::table('control_operations')->where('id', $operation->id)->update([
                'effect_attempt_token' => 2,
                'target_control_oid' => str_repeat('d', 64),
                'version' => DB::raw('version + 1'),
            ]);
            self::fail('A newer managed intent outside launch_intent was accepted.');
        } catch (QueryException) {
            self::assertSame(1, $operation->refresh()->effect_attempt_token);
            self::assertSame(str_repeat('b', 64), $operation->target_control_oid);
        }

        DB::table('control_operations')->where('id', $operation->id)->update([
            'current_attempt_token' => 2,
            'effect_attempt_token' => 2,
            'target_control_oid' => str_repeat('d', 64),
            'version' => DB::raw('version + 1'),
        ]);
        self::assertSame(2, $operation->refresh()->effect_attempt_token);
        self::assertSame(str_repeat('d', 64), $operation->target_control_oid);

        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::EFFECT_STAGED->value,
            'version' => DB::raw('version + 1'),
        ]);
        try {
            DB::table('control_operations')->where('id', $operation->id)->update([
                'phase' => ControlOperationPhase::LAUNCH_INTENT->value,
                'current_attempt_token' => 3,
                'effect_attempt_token' => 3,
                'target_control_oid' => str_repeat('f', 64),
                'version' => DB::raw('version + 1'),
            ]);
            self::fail('A staged managed intent was rebound by rolling its phase back to launch_intent.');
        } catch (QueryException) {
            self::assertSame(ControlOperationPhase::EFFECT_STAGED, $operation->refresh()->phase);
            self::assertSame(2, $operation->current_attempt_token);
            self::assertSame(2, $operation->effect_attempt_token);
            self::assertSame(str_repeat('d', 64), $operation->target_control_oid);
        }

        foreach ([str_repeat('e', 64), strtoupper(str_repeat('d', 64))] as $invalidOid) {
            try {
                DB::table('control_operations')->where('id', $operation->id)->update([
                    'target_control_oid' => $invalidOid,
                    'version' => DB::raw('version + 1'),
                ]);
                self::fail('An invalid or mutated managed-clone intent was accepted.');
            } catch (QueryException) {
                self::assertSame(str_repeat('d', 64), $operation->refresh()->target_control_oid);
            }
        }
    }

    public function test_migration_chain_restores_the_exact_ai6_006c_contract_on_ordered_down(): void
    {
        $configMigration = require database_path('migrations/2026_08_13_000000_add_project_configuration_snapshot_contract.php');
        $mutationMigration = require database_path('migrations/2026_08_12_000000_add_ticket_mutation_operation_contract.php');
        $validationMigration = require database_path('migrations/2026_08_11_000000_add_ticket_validation_projection_contract.php');
        $refreshMigration = require database_path('migrations/2026_08_10_010000_add_ticket_refresh_read_model_contract.php');
        $branchMigration = require database_path('migrations/2026_08_10_000000_add_control_branch_change_operation_contract.php');
        $cloneMigration = require database_path('migrations/2026_08_09_000000_add_clone_fetch_control_operation_contract.php');
        self::assertTrue(Schema::hasColumn('control_operations', 'target_control_oid'));

        $configMigration->down();
        $mutationMigration->down();
        $validationMigration->down();
        $refreshMigration->down();
        $branchMigration->down();
        $cloneMigration->down();
        self::assertFalse(Schema::hasColumn('control_operations', 'target_control_oid'));
        $downTrigger = (string) DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'control_operations_insert_guard')
            ->value('sql');
        self::assertStringContainsString("'deploy_key_provision'", $downTrigger);
        self::assertStringNotContainsString('managed_clone', $downTrigger);

        $cloneMigration->up();
        self::assertTrue(Schema::hasColumn('control_operations', 'target_control_oid'));
        $upTrigger = (string) DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'control_operations_insert_guard')
            ->value('sql');
        self::assertStringContainsString('managed_clone', $upTrigger);
        self::assertStringContainsString('binding_finalized', $upTrigger);

        $branchMigration->up();
        $refreshMigration->up();
        $validationMigration->up();
        $mutationMigration->up();
        $configMigration->up();
        $latestTrigger = (string) DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'control_operations_insert_guard')
            ->value('sql');
        self::assertStringContainsString('control_branch_change', $latestTrigger);
        self::assertStringContainsString('remote_probed', $latestTrigger);
        self::assertStringContainsString('config_refresh', $latestTrigger);
    }

    public function test_launch_fetch_publish_and_cleanup_keep_the_single_process_and_effect_lock_boundaries(): void
    {
        $stage = $this->methodSource(ManagedCloneSynchronizer::class, 'stage');
        self::assertStringContainsString('startManagedClone(', $stage);
        self::assertStringContainsString('startFetchToAttemptRef(', $stage);
        self::assertLessThan(strpos($stage, 'startManagedClone('), strpos($stage, "'phase' => ControlOperationPhase::LAUNCH_INTENT"));
        self::assertLessThan(strpos($stage, '$blocked->release()'), strpos($stage, "'phase' => ControlOperationPhase::PROCESS_STARTED"));

        $publish = $this->methodSource(ManagedCloneSynchronizer::class, 'publishOutcome');
        self::assertLessThan(strpos($publish, 'publishStagedRepository('), strpos($publish, 'acquireEffectLock('));
        self::assertLessThan(strpos($publish, 'updateRef('), strpos($publish, 'acquireEffectLock('));
        self::assertLessThan(strrpos($publish, '$lock->release()'), strpos($publish, 'updateRef('));

        $finalize = $this->methodSource(ManagedCloneSynchronizer::class, 'finalizeBinding');
        self::assertLessThan(strpos($finalize, "'control_oid' => \$targetOid"), strpos($finalize, 'acquireEffectLock('));
        self::assertStringContainsString("->where('operation_lock_operation_id', \$operation->id)", $finalize);
        self::assertStringContainsString("->where('operation_lock_attempt_token', \$attemptToken)", $finalize);
        self::assertStringContainsString("->where('control_binding_version', \$expectedVersion)", $finalize);

        $cleanup = $this->methodSource(ManagedCloneSynchronizer::class, 'cleanupFailedAttempt');
        self::assertLessThan(strpos($cleanup, 'deleteAttemptRef('), strpos($cleanup, 'acquireEffectLock('));
        self::assertLessThan(strpos($cleanup, 'removeOwnedOperation('), strpos($cleanup, 'acquireEffectLock('));

        $fetch = $this->methodSource(HardenedGitRunner::class, 'fetchAttemptRequest');
        foreach (['--no-write-fetch-head', '--no-auto-maintenance', '--no-recurse-submodules', '--no-tags', 'gc.auto=0', 'maintenance.auto=false', 'core.logAllRefUpdates=false'] as $argument) {
            self::assertStringContainsString($argument, $fetch);
        }
        self::assertStringContainsString("'+'.\$validated->ref.':'.\$attemptRef", $fetch);
        self::assertStringNotContainsString("'FETCH_HEAD'", $fetch);
    }

    public function test_managed_clone_web_reads_and_commands_have_no_git_process_or_repository_read_path(): void
    {
        $root = dirname(__DIR__, 3);
        $files = [
            $root.'/app/AI6/Git/Http/ControlOperationController.php',
            $root.'/app/AI6/Projects/ProjectSynchronizationStatus.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:exec|shell_exec|system|passthru|proc_open|popen)\s*\(/',
                $source,
                $file,
            );
            foreach ([
                'App\\AI6\\Shared\\Process',
                'Symfony\\Component\\Process',
                'Illuminate\\Support\\Facades\\Process',
                'HardenedGitRunner',
                'ManagedCloneSynchronizer',
                'repositoryDirectory(',
                'stagedRepository(',
                'assertRepository(',
                'file_get_contents(',
                'scandir(',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
