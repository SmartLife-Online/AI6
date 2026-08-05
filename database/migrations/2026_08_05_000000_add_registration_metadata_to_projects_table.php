<?php

use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('remote')->nullable();
            $table->string('control_branch')->nullable();
            $table->char('project_identifier', 32)->nullable()->unique();
            $table->string('host_key_fingerprint')->nullable();
            $table->string('deploy_key_reference')->nullable();
            $table->text('public_deploy_key')->nullable();
            $table->string('provisioning_status')->default(ProjectProvisioningStatus::NOT_PROVISIONED->value);
            $table->string('provisioning_operation_id')->nullable();
            $table->string('operation_lock_operation_id')->nullable();
            $table->timestamp('operation_lock_lease_expires_at')->nullable();
            $table->timestamp('operation_lock_heartbeat_at')->nullable();
            $table->unsignedBigInteger('operation_lock_attempt_token')->default(0);
            $table->unsignedBigInteger('control_generation')->default(0);
            $table->string('control_oid', 64)->nullable();
            $table->unsignedBigInteger('control_binding_version')->default(0);
            $table->string('pending_control_ref')->nullable();
            $table->string('pending_control_oid', 64)->nullable();
            $table->string('pending_control_operation_id')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER projects_registration_metadata_insert
            BEFORE INSERT ON projects
            WHEN
                ((NEW.remote IS NULL) + (NEW.control_branch IS NULL) + (NEW.project_identifier IS NULL) + (NEW.host_key_fingerprint IS NULL)) NOT IN (0, 4)
                OR (NEW.project_identifier IS NOT NULL AND (
                    length(NEW.project_identifier) <> 32
                    OR NEW.project_identifier GLOB '*[^0-9a-f]*'
                ))
                OR NEW.provisioning_status NOT IN ('not_provisioned', 'provisioning', 'provisioned', 'provisioning_failed')
                OR NEW.operation_lock_attempt_token < 0
                OR NEW.control_generation < 0
                OR NEW.control_binding_version < 0
                OR ((NEW.pending_control_ref IS NULL) + (NEW.pending_control_oid IS NULL) + (NEW.pending_control_operation_id IS NULL)) NOT IN (0, 3)
            BEGIN
                SELECT RAISE(ABORT, 'invalid project registration metadata');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER projects_registration_metadata_update
            BEFORE UPDATE ON projects
            WHEN
                ((NEW.remote IS NULL) + (NEW.control_branch IS NULL) + (NEW.project_identifier IS NULL) + (NEW.host_key_fingerprint IS NULL)) NOT IN (0, 4)
                OR (NEW.project_identifier IS NOT NULL AND (
                    length(NEW.project_identifier) <> 32
                    OR NEW.project_identifier GLOB '*[^0-9a-f]*'
                ))
                OR NEW.provisioning_status NOT IN ('not_provisioned', 'provisioning', 'provisioned', 'provisioning_failed')
                OR NEW.operation_lock_attempt_token < OLD.operation_lock_attempt_token
                OR NEW.control_generation < OLD.control_generation
                OR NEW.control_binding_version < OLD.control_binding_version
                OR ((NEW.pending_control_ref IS NULL) + (NEW.pending_control_oid IS NULL) + (NEW.pending_control_operation_id IS NULL)) NOT IN (0, 3)
            BEGIN
                SELECT RAISE(ABORT, 'invalid project registration metadata');
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS projects_registration_metadata_update');
        DB::unprepared('DROP TRIGGER IF EXISTS projects_registration_metadata_insert');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['project_identifier']);
            $table->dropColumn([
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
            ]);
        });
    }
};
