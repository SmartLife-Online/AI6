<?php

namespace Tests\Feature\Projects;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProjectRegistrationSchemaTest extends ProjectRegistrationTestCase
{
    public function test_schema_and_registration_defaults_cover_all_authoritative_metadata(): void
    {
        $expected = [
            'remote',
            'control_branch',
            'project_identifier',
            'host_key_fingerprint',
            'deploy_key_reference',
            'public_deploy_key',
            'provisioning_status',
            'provisioning_operation_id',
            'operation_lock_operation_id',
            'operation_lock_lease_expires_at',
            'operation_lock_heartbeat_at',
            'operation_lock_attempt_token',
            'control_generation',
            'control_oid',
            'control_binding_version',
            'pending_control_ref',
            'pending_control_oid',
            'pending_control_operation_id',
        ];

        foreach ($expected as $column) {
            self::assertTrue(Schema::hasColumn('projects', $column), $column);
        }
        foreach (['managed_path', 'absolute_managed_path', 'repository_path'] as $forbidden) {
            self::assertFalse(Schema::hasColumn('projects', $forbidden), $forbidden);
        }

        $administrator = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($administrator)->post(route('projects.store'), $this->registrationPayload());
        $project = Project::query()->sole();

        self::assertSame(0, $project->operation_lock_attempt_token);
        self::assertSame(0, $project->control_generation);
        self::assertSame(0, $project->control_binding_version);
        self::assertNull($project->operation_lock_operation_id);
        self::assertNull($project->operation_lock_lease_expires_at);
        self::assertNull($project->operation_lock_heartbeat_at);
        self::assertNull($project->control_oid);
        self::assertNull($project->pending_control_ref);
        self::assertNull($project->pending_control_oid);
        self::assertNull($project->pending_control_operation_id);
        self::assertNull($project->deploy_key_reference);
        self::assertNull($project->public_deploy_key);
        self::assertNull($project->provisioning_operation_id);
        self::assertSame(ProjectProvisioningStatus::NOT_PROVISIONED, $project->provisioning_status);
    }

    public function test_database_rejects_invalid_or_duplicate_identifiers(): void
    {
        $registrationMetadata = [
            'remote' => 'git@git.example.test:acme/project.git',
            'control_branch' => 'refs/heads/main',
            'host_key_fingerprint' => $this->fingerprint,
        ];

        foreach (['short', str_repeat('A', 32), str_repeat('a', 31).'/'] as $identifier) {
            try {
                DB::table('projects')->insert([
                    'name' => 'Ungültig '.$identifier,
                    ...$registrationMetadata,
                    'project_identifier' => $identifier,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                self::fail('The database unexpectedly accepted an invalid project identifier.');
            } catch (QueryException) {
                self::assertSame(0, Project::query()->count());
            }
        }

        $identifier = str_repeat('a', 32);
        Project::query()->create([
            'name' => 'Erstes Projekt',
            ...$registrationMetadata,
            'project_identifier' => $identifier,
        ]);

        $this->expectException(QueryException::class);
        Project::query()->create([
            'name' => 'Doppeltes Projekt',
            ...$registrationMetadata,
            'project_identifier' => $identifier,
        ]);
    }

    public function test_database_requires_registration_metadata_as_a_complete_tuple(): void
    {
        $partialTuples = [
            ['remote' => 'git@git.example.test:acme/project.git'],
            ['control_branch' => 'refs/heads/main'],
            ['project_identifier' => str_repeat('a', 32)],
            ['host_key_fingerprint' => $this->fingerprint],
        ];

        foreach ($partialTuples as $index => $partialTuple) {
            try {
                Project::query()->create(['name' => 'Teilmetadaten '.$index, ...$partialTuple]);
                self::fail('The database unexpectedly accepted partial registration metadata.');
            } catch (QueryException) {
                self::assertSame(0, Project::query()->count());
            }
        }

        $legacyProject = Project::query()->create(['name' => 'Nicht registriertes Projekt']);

        try {
            $legacyProject->update(['remote' => 'git@git.example.test:acme/project.git']);
            self::fail('The database unexpectedly accepted a partial registration metadata update.');
        } catch (QueryException) {
            $legacyProject->refresh();
            self::assertNull($legacyProject->remote);
            self::assertNull($legacyProject->control_branch);
            self::assertNull($legacyProject->project_identifier);
            self::assertNull($legacyProject->host_key_fingerprint);
        }
    }

    public function test_database_rejects_counter_regressions_and_partial_pending_binding(): void
    {
        $project = Project::query()->create(['name' => 'Versionsprojekt']);
        $project->update([
            'operation_lock_attempt_token' => 2,
            'control_generation' => 2,
            'control_binding_version' => 2,
        ]);

        foreach (['operation_lock_attempt_token', 'control_generation', 'control_binding_version'] as $column) {
            try {
                DB::table('projects')->where('id', $project->getKey())->update([$column => 1]);
                self::fail('The database unexpectedly accepted a counter regression.');
            } catch (QueryException) {
                self::assertSame(2, DB::table('projects')->where('id', $project->getKey())->value($column));
            }
        }

        $this->expectException(QueryException::class);
        DB::table('projects')->where('id', $project->getKey())->update([
            'pending_control_ref' => 'refs/heads/main',
        ]);
    }
}
