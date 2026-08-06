<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationAuthorizationSnapshot;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
}
