<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropOperationGuards();
        Schema::create('control_branch_audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->string('control_operation_id')->unique();
            $table->foreign('control_operation_id')->references('id')->on('control_operations');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('old_branch');
            $table->string('new_branch');
            $table->char('old_control_oid', 64);
            $table->char('new_control_oid', 64);
            $table->timestamp('occurred_at');
            $table->index(['project_id', 'occurred_at']);
        });
        $this->createAuditGuards();
        $this->createOperationGuards(includeControlBranchChange: true);
    }

    public function down(): void
    {
        $this->dropOperationGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS control_branch_audit_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_branch_audit_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_branch_audit_insert_guard');
        Schema::dropIfExists('control_branch_audit_entries');
        $this->createOperationGuards(includeControlBranchChange: false);
    }

    private function dropOperationGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_insert_guard');
    }

    private function createAuditGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_branch_audit_insert_guard
            BEFORE INSERT ON control_branch_audit_entries
            WHEN
                NEW.old_branch = '' OR length(NEW.old_branch) > 255
                OR NEW.new_branch = '' OR length(NEW.new_branch) > 255
                OR length(NEW.old_control_oid) <> 64 OR NEW.old_control_oid GLOB '*[^0-9a-f]*'
                OR length(NEW.new_control_oid) <> 64 OR NEW.new_control_oid GLOB '*[^0-9a-f]*'
                OR NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id
                      AND project_id = NEW.project_id
                      AND actor_id = NEW.actor_id
                      AND operation_type = 'control_branch_change'
                      AND state = 'running'
                      AND phase = 'remote_probed'
                      AND expected_control_commit = NEW.old_control_oid
                      AND target_control_oid = NEW.new_control_oid
                      AND json_extract(operation_parameters_jcs, '$.old_control_ref') = NEW.old_branch
                      AND json_extract(operation_parameters_jcs, '$.new_control_ref') = NEW.new_branch
                )
            BEGIN
                SELECT RAISE(ABORT, 'invalid control branch audit entry');
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_branch_audit_update_guard
            BEFORE UPDATE ON control_branch_audit_entries
            BEGIN
                SELECT RAISE(ABORT, 'control branch audit entries are immutable');
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_branch_audit_delete_guard
            BEFORE DELETE ON control_branch_audit_entries
            BEGIN
                SELECT RAISE(ABORT, 'control branch audit entries are immutable');
            END
            SQL);
    }

    private function createOperationGuards(bool $includeControlBranchChange): void
    {
        $types = $includeControlBranchChange
            ? "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change'"
            : "'deploy_key_provision', 'managed_clone', 'managed_fetch'";
        $phases = $includeControlBranchChange
            ? "'queued', 'claimed', 'remote_probed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed', 'recovery_required'"
            : "'queued', 'claimed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed', 'recovery_required'";
        $branchInsert = $includeControlBranchChange ? <<<'SQL'
                OR (NEW.operation_type = 'control_branch_change' AND NEW.effect_attempt_token IS NOT NULL)
                OR (NEW.operation_type = 'control_branch_change' AND NEW.target_control_oid IS NOT NULL)
                OR (NEW.operation_type = 'control_branch_change' AND NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized'))
            SQL : '';
        $branchUpdate = $includeControlBranchChange ? <<<'SQL'
                OR (NEW.operation_type = 'control_branch_change' AND NEW.effect_attempt_token IS NOT NULL)
                OR (NEW.operation_type = 'control_branch_change' AND NEW.phase = 'remote_probed' AND NEW.target_control_oid IS NULL)
                OR (NEW.operation_type = 'control_branch_change' AND OLD.target_control_oid IS NULL AND NEW.target_control_oid IS NOT NULL AND NOT (
                    OLD.phase = 'claimed'
                    AND NEW.phase = 'remote_probed'
                    AND NEW.state = 'running'
                ))
                OR (NEW.operation_type = 'control_branch_change' AND OLD.target_control_oid IS NOT NULL AND NEW.target_control_oid IS NOT OLD.target_control_oid)
                OR (NEW.operation_type = 'control_branch_change' AND NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized'))
            SQL : '';

        DB::unprepared(<<<SQL
            CREATE TRIGGER control_operations_insert_guard
            BEFORE INSERT ON control_operations
            WHEN
                NEW.schema_version <> 1
                OR NEW.operation_type NOT IN ($types)
                OR NEW.phase NOT IN ($phases)
                OR NEW.state NOT IN ('queued', 'running', 'recovery_required', 'completed', 'failed', 'abandoned')
                OR length(NEW.request_hash) <> 64 OR NEW.request_hash GLOB '*[^0-9a-f]*'
                OR (NEW.expected_control_commit IS NOT NULL AND (length(NEW.expected_control_commit) <> 64 OR NEW.expected_control_commit GLOB '*[^0-9a-f]*'))
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.target_control_oid IS NOT NULL)
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND ((NEW.target_control_oid IS NULL) <> (NEW.effect_attempt_token IS NULL)))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('launch_intent', 'process_started', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.target_control_oid IS NULL)
                OR (NEW.target_control_oid IS NOT NULL AND (length(NEW.target_control_oid) <> 64 OR NEW.target_control_oid GLOB '*[^0-9a-f]*'))
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.phase IN ('effect_staged', 'outcome_published', 'binding_finalized'))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('key_generated', 'key_activated', 'provisioning_finalized'))
                $branchInsert
                OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_version IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_effect_hash IS NULL))
                OR (NEW.finding_hash IS NOT NULL AND (length(NEW.finding_hash) <> 64 OR NEW.finding_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.recovery_effect_hash IS NOT NULL AND (length(NEW.recovery_effect_hash) <> 64 OR NEW.recovery_effect_hash GLOB '*[^0-9a-f]*'))
                OR ((NEW.process_id IS NULL) <> (NEW.process_started_at IS NULL))
                OR (NEW.launch_argument_hash IS NOT NULL AND (length(NEW.launch_argument_hash) <> 64 OR NEW.launch_argument_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.launch_argument_hash IS NULL)
                OR (NEW.state = 'recovery_required') <> (NEW.phase = 'recovery_required')
                OR (NEW.state = 'recovery_required') <> (NEW.recovery_attempt_token IS NOT NULL)
                OR (NEW.state IN ('completed', 'failed', 'abandoned') AND NEW.completed_at IS NULL)
                OR NEW.state IN ('completed', 'failed', 'abandoned')
            BEGIN
                SELECT RAISE(ABORT, 'invalid control operation');
            END
            SQL);

        DB::unprepared(<<<SQL
            CREATE TRIGGER control_operations_update_guard
            BEFORE UPDATE ON control_operations
            WHEN
                NEW.schema_version <> 1
                OR NEW.id <> OLD.id
                OR NEW.project_id <> OLD.project_id
                OR NEW.actor_id <> OLD.actor_id
                OR NEW.operation_type <> OLD.operation_type
                OR NEW.schema_version <> OLD.schema_version
                OR NEW.authorization_snapshot <> OLD.authorization_snapshot
                OR NEW.authorization_snapshot_jcs <> OLD.authorization_snapshot_jcs
                OR NEW.expected_control_commit IS NOT OLD.expected_control_commit
                OR NEW.operation_parameters_jcs <> OLD.operation_parameters_jcs
                OR NEW.request_hash <> OLD.request_hash
                OR NEW.operation_type NOT IN ($types)
                OR NEW.phase NOT IN ($phases)
                OR NEW.state NOT IN ('queued', 'running', 'recovery_required', 'completed', 'failed', 'abandoned')
                OR NEW.attempts < OLD.attempts OR NEW.version < OLD.version
                OR (OLD.current_attempt_token IS NOT NULL AND (NEW.current_attempt_token IS NULL OR NEW.current_attempt_token < OLD.current_attempt_token))
                OR length(NEW.request_hash) <> 64 OR NEW.request_hash GLOB '*[^0-9a-f]*'
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.target_control_oid IS NOT NULL)
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND ((NEW.target_control_oid IS NULL) <> (NEW.effect_attempt_token IS NULL)))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('launch_intent', 'process_started', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.target_control_oid IS NULL)
                OR (NEW.target_control_oid IS NOT NULL AND (length(NEW.target_control_oid) <> 64 OR NEW.target_control_oid GLOB '*[^0-9a-f]*'))
                OR (OLD.effect_attempt_token IS NOT NEW.effect_attempt_token AND NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.effect_attempt_token IS NOT NULL AND NOT (
                    OLD.phase IN ('claimed', 'launch_intent', 'process_started')
                    AND NEW.effect_attempt_token = NEW.current_attempt_token
                    AND NEW.state = 'running'
                    AND NEW.phase = 'launch_intent'
                ))
                OR (OLD.target_control_oid IS NOT NULL AND NEW.target_control_oid IS NOT OLD.target_control_oid AND NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NOT (
                    OLD.phase IN ('claimed', 'launch_intent', 'process_started')
                    AND OLD.effect_attempt_token IS NOT NEW.effect_attempt_token
                    AND NEW.effect_attempt_token = NEW.current_attempt_token
                    AND NEW.state = 'running'
                    AND NEW.phase = 'launch_intent'
                ))
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.phase IN ('effect_staged', 'outcome_published', 'binding_finalized'))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('key_generated', 'key_activated', 'provisioning_finalized'))
                $branchUpdate
                OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_version IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_effect_hash IS NULL))
                OR (NEW.finding_hash IS NOT NULL AND (length(NEW.finding_hash) <> 64 OR NEW.finding_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.recovery_effect_hash IS NOT NULL AND (length(NEW.recovery_effect_hash) <> 64 OR NEW.recovery_effect_hash GLOB '*[^0-9a-f]*'))
                OR ((NEW.process_id IS NULL) <> (NEW.process_started_at IS NULL))
                OR (NEW.launch_argument_hash IS NOT NULL AND (length(NEW.launch_argument_hash) <> 64 OR NEW.launch_argument_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.launch_argument_hash IS NULL)
                OR (NEW.state = 'recovery_required') <> (NEW.phase = 'recovery_required')
                OR (NEW.state = 'recovery_required') <> (NEW.recovery_attempt_token IS NOT NULL)
                OR (OLD.state IN ('completed', 'failed', 'abandoned') AND NEW.state <> OLD.state)
                OR (NEW.state IN ('completed', 'failed', 'abandoned') AND NEW.completed_at IS NULL)
                OR (NEW.state IN ('completed', 'failed', 'abandoned') AND NOT EXISTS (
                    SELECT 1 FROM control_operation_results WHERE control_operation_id = NEW.id
                ))
            BEGIN
                SELECT RAISE(ABORT, 'invalid control operation transition');
            END
            SQL);
    }
};
