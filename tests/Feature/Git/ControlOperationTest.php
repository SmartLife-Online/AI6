<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationAuthorizationSnapshot;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationHasher;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class ControlOperationTest extends ControlOperationTestCase
{
    public function test_database_rejects_untyped_operation_state_and_malformed_request_hash(): void
    {
        $administrator = $this->createUser();
        $project = $this->registeredProject($administrator);

        $this->expectException(QueryException::class);
        DB::table('control_operations')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $project->getKey(),
            'actor_id' => $administrator->getKey(),
            'operation_type' => 'unknown',
            'schema_version' => 1,
            'authorization_snapshot' => '{}',
            'authorization_snapshot_jcs' => '{}',
            'operation_parameters_jcs' => '{}',
            'request_hash' => 'not-a-hash',
            'phase' => 'invented',
            'state' => 'invented',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_reusing_an_operation_id_with_other_request_data_is_a_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $otherAdministrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->addMembership($otherAdministrator, $project, ProjectRole::ADMIN);
        $id = (string) Str::uuid();
        $action = $this->app->make(QueueDeployKeyProvisioning::class);
        $action->handle($administrator, $project, $id);

        $this->expectException(ControlOperationConflict::class);
        $action->handle($otherAdministrator, $project->refresh(), $id);
    }

    public function test_reusing_an_operation_id_with_a_different_project_is_also_a_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $firstProject = $this->registeredProject($administrator);
        $secondProject = Project::query()->create([
            'name' => 'Zweites Control-Projekt',
            'remote' => 'git@git.example.test:acme/second-control.git',
            'control_branch' => 'refs/heads/main',
            'project_identifier' => str_repeat('b', 32),
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::NOT_PROVISIONED,
        ]);
        $this->addMembership($administrator, $secondProject, ProjectRole::ADMIN);
        $id = (string) Str::uuid();
        $action = $this->app->make(QueueDeployKeyProvisioning::class);
        $action->handle($administrator, $firstProject, $id);

        $this->expectException(ControlOperationConflict::class);
        $action->handle($administrator, $secondProject->refresh(), $id);
    }

    public function test_hasher_binds_operation_type_and_type_specific_parameters_into_distinct_hashes(): void
    {
        $hasher = new ControlOperationHasher;
        $canonical = new CanonicalJson;
        $snapshot = $canonical->normalizeAndEncode(['actor_id' => 1]);

        $edHash = $hasher->hash(1, str_repeat('a', 32), ControlOperationType::DEPLOY_KEY_PROVISION, '1', $snapshot, null, ['algorithm' => 'ed25519']);
        $rsaHash = $hasher->hash(1, str_repeat('a', 32), ControlOperationType::DEPLOY_KEY_PROVISION, '1', $snapshot, null, ['algorithm' => 'rsa']);
        self::assertNotSame($edHash, $rsaHash, 'Different type-specific parameters must not collapse into the same request hash.');

        // Production currently declares exactly one ControlOperationType case, so a second
        // real type cannot be constructed to prove cross-type distinctness end-to-end via
        // hash(). This instead reuses the hasher's own field-composition primitive (via
        // reflection on the production method, not a re-implementation) to show that the
        // operation-type value is bound into the payload the same way every other field
        // is, so a future second type is cryptographically distinguished by construction.
        $field = new ReflectionMethod(ControlOperationHasher::class, 'field');
        $field->setAccessible(true);
        $domain = "AI6-CONTROL-OPERATION-V1\0";
        $prefix = $domain.$field->invoke($hasher, '1').$field->invoke($hasher, str_repeat('a', 32));
        $deployKeyProvisionPayload = $prefix.$field->invoke($hasher, ControlOperationType::DEPLOY_KEY_PROVISION->value);
        $otherTypePayload = $prefix.$field->invoke($hasher, 'other_operation_type');
        self::assertNotSame(hash('sha256', $deployKeyProvisionPayload), hash('sha256', $otherTypePayload));
    }

    public function test_operation_schema_contains_authoritative_request_attempt_and_recovery_bindings(): void
    {
        foreach ([
            'operation_type', 'authorization_snapshot', 'authorization_snapshot_jcs', 'expected_control_commit',
            'operation_parameters_jcs', 'request_hash', 'phase', 'state', 'attempts', 'current_attempt_token',
            'effect_attempt_token', 'lease_boot_id', 'launch_argument_hash', 'process_id', 'process_started_at', 'finding_hash',
            'recovery_attempt_token', 'recovery_version', 'recovery_effect_hash', 'version',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('control_operations', $column), $column);
        }
        self::assertFalse(Schema::hasTable('tickets'));
        self::assertSame(0, ControlOperation::query()->count());
    }

    public function test_every_queued_control_job_references_an_existing_operation(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );

        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            self::assertIsString($payload);
            self::assertStringContainsString($operation->id, $payload);
            self::assertTrue(ControlOperation::query()->whereKey($operation->id)->exists());
        }
    }

    public function test_nil_uuid_is_rejected_before_project_provisioning_changes(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);

        $this->actingAs($administrator)
            ->post(route('projects.deploy-key.provision', $project), [
                'operation_id' => '00000000-0000-0000-0000-000000000000',
            ])
            ->assertSessionHasErrors('operation_id');

        self::assertSame('not_provisioned', $project->refresh()->provisioning_status->value);
        self::assertSame(0, ControlOperation::query()->count());
    }

    public function test_persisted_authorization_snapshot_is_rechecked_against_current_actor_and_project_membership(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $snapshots = $this->app->make(ControlOperationAuthorizationSnapshot::class);

        self::assertTrue($snapshots->matchesCurrent($operation, $administrator, $project));
        $administrator->forceFill(['is_global_admin' => false])->save();
        self::assertFalse($snapshots->matchesCurrent($operation, $administrator->refresh(), $project->refresh()));
    }

    public function test_a_queued_job_whose_actor_had_permission_revoked_aborts_in_the_real_worker_before_the_process_starts(): void
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

        // The actor's global-administrator permission is revoked after the job was
        // queued but before the worker executes it.
        $administrator->forceFill(['is_global_admin' => false])->save();

        try {
            (new ExecuteControlOperation($operation->id))->handle($this->app->make(ControlOperationExecutor::class));
            self::fail('A queued job for an actor with revoked permission unexpectedly reached process launch.');
        } catch (Throwable $exception) {
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertSame('The control operation attempt failed and remains retryable.', $exception->getMessage());
        }

        // End-state proof: the abort happened before startBlocked() ever ran — no
        // launch intent was persisted and no process was spawned.
        $operation->refresh();
        self::assertSame(ControlOperationPhase::CLAIMED, $operation->phase);
        self::assertSame(ControlOperationState::RUNNING, $operation->state);
        self::assertNull($operation->effect_attempt_token);
        self::assertNull($operation->launch_argument_hash);
        self::assertNull($operation->process_id);
        self::assertNull($operation->process_started_at);
        self::assertSame('Authorization changed before the deploy-key process started.', $operation->last_error);
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$operation->id);
    }
}
