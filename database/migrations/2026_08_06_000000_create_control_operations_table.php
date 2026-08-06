<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation_type');
            $table->unsignedInteger('schema_version');
            $table->json('authorization_snapshot');
            $table->text('authorization_snapshot_jcs');
            $table->char('expected_control_commit', 64)->nullable();
            $table->text('operation_parameters_jcs');
            $table->char('request_hash', 64);
            $table->string('phase');
            $table->string('state');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('current_attempt_token')->nullable();
            $table->unsignedBigInteger('effect_attempt_token')->nullable();
            $table->char('lease_boot_id', 32)->nullable();
            $table->char('launch_argument_hash', 64)->nullable();
            $table->unsignedBigInteger('process_id')->nullable();
            $table->timestamp('process_started_at')->nullable();
            $table->char('key_fingerprint', 64)->nullable();
            $table->text('finding_text')->nullable();
            $table->char('finding_hash', 64)->nullable();
            $table->unsignedBigInteger('recovery_attempt_token')->nullable();
            $table->unsignedBigInteger('recovery_version')->nullable();
            $table->char('recovery_effect_hash', 64)->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'request_hash']);
            $table->index(['state', 'updated_at']);
        });

        Schema::create('control_operation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('control_operation_id')->unique()->constrained('control_operations')->cascadeOnDelete();
            $table->string('outcome');
            $table->char('result_binding', 64);
            $table->text('safe_summary');
            $table->timestamps();
        });

        Schema::create('control_operation_recovery_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_operation_id')->constrained('control_operations')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->unsignedBigInteger('expected_attempt_token');
            $table->unsignedBigInteger('expected_operation_version');
            $table->char('finding_hash', 64);
            $table->text('reason')->nullable();
            $table->text('bound_evidence')->nullable();
            $table->unsignedBigInteger('application_attempt_token')->nullable();
            $table->char('application_fingerprint', 64)->nullable();
            $table->string('state')->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['control_operation_id', 'state']);
        });

        DB::unprepared("CREATE UNIQUE INDEX control_operation_one_pending_recovery ON control_operation_recovery_decisions (control_operation_id) WHERE state = 'pending'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_operations_insert_guard
            BEFORE INSERT ON control_operations
            WHEN
                NEW.schema_version <> 1
                OR NEW.operation_type NOT IN ('deploy_key_provision')
                OR NEW.phase NOT IN ('queued', 'claimed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'attempt_completed', 'recovery_required')
                OR NEW.state NOT IN ('queued', 'running', 'recovery_required', 'completed', 'failed', 'abandoned')
                OR length(NEW.request_hash) <> 64 OR NEW.request_hash GLOB '*[^0-9a-f]*'
                OR (NEW.expected_control_commit IS NOT NULL AND (length(NEW.expected_control_commit) <> 64 OR NEW.expected_control_commit GLOB '*[^0-9a-f]*'))
                OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_version IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_effect_hash IS NULL))
                OR (NEW.finding_hash IS NOT NULL AND (length(NEW.finding_hash) <> 64 OR NEW.finding_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.recovery_effect_hash IS NOT NULL AND (length(NEW.recovery_effect_hash) <> 64 OR NEW.recovery_effect_hash GLOB '*[^0-9a-f]*'))
                OR ((NEW.process_id IS NULL) <> (NEW.process_started_at IS NULL))
                OR (NEW.launch_argument_hash IS NOT NULL AND (length(NEW.launch_argument_hash) <> 64 OR NEW.launch_argument_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized') AND NEW.launch_argument_hash IS NULL)
                OR (NEW.state = 'recovery_required') <> (NEW.phase = 'recovery_required')
                OR (NEW.state = 'recovery_required') <> (NEW.recovery_attempt_token IS NOT NULL)
                OR (NEW.state IN ('completed', 'failed', 'abandoned') AND NEW.completed_at IS NULL)
                OR NEW.state IN ('completed', 'failed', 'abandoned')
            BEGIN
                SELECT RAISE(ABORT, 'invalid control operation');
            END
            SQL);

        DB::unprepared(<<<'SQL'
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
                OR NEW.operation_type NOT IN ('deploy_key_provision')
                OR NEW.phase NOT IN ('queued', 'claimed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'attempt_completed', 'recovery_required')
                OR NEW.state NOT IN ('queued', 'running', 'recovery_required', 'completed', 'failed', 'abandoned')
                OR NEW.attempts < OLD.attempts OR NEW.version < OLD.version
                OR (OLD.current_attempt_token IS NOT NULL AND (NEW.current_attempt_token IS NULL OR NEW.current_attempt_token < OLD.current_attempt_token))
                OR length(NEW.request_hash) <> 64 OR NEW.request_hash GLOB '*[^0-9a-f]*'
                OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_version IS NULL))
                OR ((NEW.recovery_attempt_token IS NULL) <> (NEW.recovery_effect_hash IS NULL))
                OR (NEW.finding_hash IS NOT NULL AND (length(NEW.finding_hash) <> 64 OR NEW.finding_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.recovery_effect_hash IS NOT NULL AND (length(NEW.recovery_effect_hash) <> 64 OR NEW.recovery_effect_hash GLOB '*[^0-9a-f]*'))
                OR ((NEW.process_id IS NULL) <> (NEW.process_started_at IS NULL))
                OR (NEW.launch_argument_hash IS NOT NULL AND (length(NEW.launch_argument_hash) <> 64 OR NEW.launch_argument_hash GLOB '*[^0-9a-f]*'))
                OR (NEW.phase IN ('launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized') AND NEW.launch_argument_hash IS NULL)
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

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_operation_results_guard
            BEFORE INSERT ON control_operation_results
            WHEN
                NEW.outcome NOT IN ('succeeded', 'failed', 'abandoned')
                OR length(NEW.result_binding) <> 64
                OR NEW.result_binding GLOB '*[^0-9a-f]*'
            BEGIN
                SELECT RAISE(ABORT, 'invalid control operation result');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_operation_results_update_guard
            BEFORE UPDATE ON control_operation_results
            WHEN
                NEW.control_operation_id <> OLD.control_operation_id
                OR NEW.outcome <> OLD.outcome
                OR NEW.result_binding <> OLD.result_binding
                OR NEW.safe_summary <> OLD.safe_summary
            BEGIN
                SELECT RAISE(ABORT, 'control operation results are immutable');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_operation_recovery_decisions_guard
            BEFORE INSERT ON control_operation_recovery_decisions
            WHEN
                NEW.decision NOT IN ('retry_reconciliation', 'adopt_external_state', 'abandon_operation')
                OR NEW.state NOT IN ('pending', 'applied', 'rejected')
                OR length(NEW.finding_hash) <> 64
                OR NEW.finding_hash GLOB '*[^0-9a-f]*'
                OR ((NEW.application_attempt_token IS NULL) <> (NEW.application_fingerprint IS NULL))
                OR (NEW.application_fingerprint IS NOT NULL AND (length(NEW.application_fingerprint) <> 64 OR NEW.application_fingerprint GLOB '*[^0-9a-f]*'))
                OR (NEW.decision <> 'adopt_external_state' AND NEW.application_attempt_token IS NOT NULL)
                OR (NEW.state = 'applied' AND NEW.decision = 'adopt_external_state' AND NEW.application_attempt_token IS NULL)
                OR (NEW.decision = 'abandon_operation' AND (trim(coalesce(NEW.reason, '')) = '' OR trim(coalesce(NEW.bound_evidence, '')) = ''))
            BEGIN
                SELECT RAISE(ABORT, 'invalid control operation recovery decision');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER control_operation_recovery_decisions_update_guard
            BEFORE UPDATE ON control_operation_recovery_decisions
            WHEN
                NEW.control_operation_id <> OLD.control_operation_id
                OR NEW.actor_id <> OLD.actor_id
                OR NEW.decision <> OLD.decision
                OR NEW.expected_attempt_token <> OLD.expected_attempt_token
                OR NEW.expected_operation_version <> OLD.expected_operation_version
                OR NEW.finding_hash <> OLD.finding_hash
                OR NEW.reason IS NOT OLD.reason
                OR NEW.bound_evidence IS NOT OLD.bound_evidence
                OR ((NEW.application_attempt_token IS NULL) <> (NEW.application_fingerprint IS NULL))
                OR (NEW.application_fingerprint IS NOT NULL AND (length(NEW.application_fingerprint) <> 64 OR NEW.application_fingerprint GLOB '*[^0-9a-f]*'))
                OR (NEW.decision <> 'adopt_external_state' AND NEW.application_attempt_token IS NOT NULL)
                OR (OLD.application_attempt_token IS NOT NULL AND (NEW.application_attempt_token IS NOT OLD.application_attempt_token OR NEW.application_fingerprint IS NOT OLD.application_fingerprint))
                OR (OLD.application_attempt_token IS NULL AND NEW.application_attempt_token IS NOT NULL AND (OLD.state <> 'pending' OR NEW.state <> 'pending'))
                OR (NEW.state = 'applied' AND NEW.decision = 'adopt_external_state' AND NEW.application_attempt_token IS NULL)
                OR NEW.state NOT IN ('pending', 'applied', 'rejected')
                OR (OLD.state <> 'pending' AND NEW.state <> OLD.state)
            BEGIN
                SELECT RAISE(ABORT, 'invalid recovery decision transition');
            END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS control_operation_recovery_decisions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operation_recovery_decisions_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operation_results_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operation_results_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS control_operations_insert_guard');
        Schema::dropIfExists('control_operation_recovery_decisions');
        Schema::dropIfExists('control_operation_results');
        Schema::dropIfExists('control_operations');
    }
};
