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
        Schema::table('control_operations', function (Blueprint $table): void {
            $table->char('target_control_oid', 64)->nullable()->after('effect_attempt_token');
        });
        $this->createOperationGuards(includeManagedClone: true);
    }

    public function down(): void
    {
        $this->dropOperationGuards();
        Schema::table('control_operations', function (Blueprint $table): void {
            $table->dropColumn('target_control_oid');
        });
        $this->createOperationGuards(includeManagedClone: false);
    }

    private function dropOperationGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_insert_guard');
    }

    private function createOperationGuards(bool $includeManagedClone): void
    {
        $types = $includeManagedClone
            ? "'deploy_key_provision', 'managed_clone', 'managed_fetch'"
            : "'deploy_key_provision'";
        $phases = $includeManagedClone
            ? "'queued', 'claimed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed', 'recovery_required'"
            : "'queued', 'claimed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'attempt_completed', 'recovery_required'";
        $intentInsert = $includeManagedClone ? <<<'SQL'
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.target_control_oid IS NOT NULL)
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND ((NEW.target_control_oid IS NULL) <> (NEW.effect_attempt_token IS NULL)))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('launch_intent', 'process_started', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.target_control_oid IS NULL)
                OR (NEW.target_control_oid IS NOT NULL AND (length(NEW.target_control_oid) <> 64 OR NEW.target_control_oid GLOB '*[^0-9a-f]*'))
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.phase IN ('effect_staged', 'outcome_published', 'binding_finalized'))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('key_generated', 'key_activated', 'provisioning_finalized'))
            SQL : '';
        $intentUpdate = $includeManagedClone ? <<<'SQL'
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.target_control_oid IS NOT NULL)
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND ((NEW.target_control_oid IS NULL) <> (NEW.effect_attempt_token IS NULL)))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('launch_intent', 'process_started', 'effect_staged', 'outcome_published', 'binding_finalized') AND NEW.target_control_oid IS NULL)
                OR (NEW.target_control_oid IS NOT NULL AND (length(NEW.target_control_oid) <> 64 OR NEW.target_control_oid GLOB '*[^0-9a-f]*'))
                OR (OLD.effect_attempt_token IS NOT NEW.effect_attempt_token AND NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.effect_attempt_token IS NOT NULL AND NOT (
                    OLD.phase IN ('claimed', 'launch_intent', 'process_started')
                    AND
                    NEW.effect_attempt_token = NEW.current_attempt_token
                    AND NEW.state = 'running'
                    AND NEW.phase = 'launch_intent'
                ))
                OR (OLD.target_control_oid IS NOT NULL AND NEW.target_control_oid IS NOT OLD.target_control_oid AND NOT (
                    OLD.phase IN ('claimed', 'launch_intent', 'process_started')
                    AND OLD.effect_attempt_token IS NOT NEW.effect_attempt_token
                    AND NEW.effect_attempt_token = NEW.current_attempt_token
                    AND NEW.state = 'running'
                    AND NEW.phase = 'launch_intent'
                ))
                OR (NEW.operation_type = 'deploy_key_provision' AND NEW.phase IN ('effect_staged', 'outcome_published', 'binding_finalized'))
                OR (NEW.operation_type IN ('managed_clone', 'managed_fetch') AND NEW.phase IN ('key_generated', 'key_activated', 'provisioning_finalized'))
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
                $intentInsert
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
                $intentUpdate
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
