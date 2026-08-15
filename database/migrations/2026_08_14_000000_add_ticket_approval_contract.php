<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh'";

    private const NEW_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh', 'ticket_approval'";

    public function up(): void
    {
        $this->rewritePublishedGuards(true);
        $this->recreateTicketMutationGuards(true);

        Schema::create('ticket_approval_previews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_read_model_id')->constrained('ticket_read_models')->cascadeOnDelete();
            $table->char('reviewed_ticket_blob_sha', 64);
            $table->char('reviewed_control_sha', 64);
            $table->char('ticket_contract_sha256', 64);
            $table->unsignedBigInteger('control_generation');
            $table->json('selection_snapshot');
            $table->string('state');
            $table->json('approval_snapshot')->nullable();
            $table->char('approval_snapshot_hash', 64)->nullable();
            $table->string('error_code')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'ticket_read_model_id', 'created_at'], 'approval_previews_ticket_index');
        });

        Schema::create('ticket_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('status_operation_id')->unique()->constrained('control_operations')->cascadeOnDelete();
            $table->string('ticket_id', 128);
            $table->string('relative_path', 1024);
            $table->char('reviewed_ticket_blob_sha', 64);
            $table->char('reviewed_control_sha', 64);
            $table->char('approved_ticket_blob_sha', 64)->nullable();
            $table->char('approved_control_sha', 64)->nullable();
            $table->char('ticket_contract_sha256', 64);
            $table->unsignedBigInteger('control_generation');
            $table->uuid('snapshot_context_id');
            $table->string('saga_phase');
            $table->char('intended_commit_sha', 64)->nullable();
            $table->json('config_snapshot');
            $table->char('config_hash', 64);
            $table->json('scope_snapshot');
            $table->char('scope_hash', 64);
            $table->json('prompt_snapshot');
            $table->char('prompt_hash', 64);
            $table->json('instruction_snapshot');
            $table->char('instruction_hash', 64);
            $table->json('runtime_profile_snapshot');
            $table->char('runtime_profile_hash', 64);
            $table->json('agent_profile_snapshot');
            $table->char('agent_profile_hash', 64);
            $table->char('security_policy_hash', 64);
            $table->json('limits_snapshot');
            $table->char('approval_snapshot_hash', 64);
            $table->string('queue_state');
            $table->foreignId('attention_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('push_mode');
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['project_id', 'ticket_id', 'approved_at']);
            $table->index(['project_id', 'queue_state', 'approved_at']);
        });

        $this->createApprovalGuards();

        Schema::create('ticket_approval_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ticket_approval_id')->constrained('ticket_approvals')->cascadeOnDelete();
            $table->string('state');
            $table->boolean('eligible')->nullable();
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->index(['ticket_approval_id', 'created_at'], 'approval_evaluations_approval_index');
        });
        DB::statement("CREATE UNIQUE INDEX ticket_approval_evaluations_queued_unique ON ticket_approval_evaluations (ticket_approval_id) WHERE state = 'queued'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_approvals_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_approvals_insert_guard');
        Schema::dropIfExists('ticket_approval_evaluations');
        Schema::dropIfExists('ticket_approvals');
        Schema::dropIfExists('ticket_approval_previews');
        $this->recreateTicketMutationGuards(false);
        $this->rewritePublishedGuards(false);
    }

    private function rewritePublishedGuards(bool $include): void
    {
        $oldTypes = $include ? self::OLD_TYPES : self::NEW_TYPES;
        $newTypes = $include ? self::NEW_TYPES : self::OLD_TYPES;
        $oldMutations = $include ? "('ticket_edit', 'ticket_status_change')" : "('ticket_edit', 'ticket_status_change', 'ticket_approval')";
        $newMutations = $include ? "('ticket_edit', 'ticket_status_change', 'ticket_approval')" : "('ticket_edit', 'ticket_status_change')";
        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace("NEW.operation_type NOT IN ($oldTypes)", "NEW.operation_type NOT IN ($newTypes)", $sql, $types);
            $sql = str_replace('NEW.operation_type IN '.$oldMutations, 'NEW.operation_type IN '.$newMutations, $sql, $mutations);
            if ($types !== 1 || $mutations !== 1) {
                throw new RuntimeException('The control-operation approval guards could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($sql);
        }

        foreach (['ticket_read_models_insert_guard', 'ticket_read_models_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace('operation_type IN '.$oldMutations, 'operation_type IN '.$newMutations, $sql, $count);
            if ($count !== 1) {
                throw new RuntimeException('The ticket-read-model approval guard could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($sql);
        }
    }

    private function recreateTicketMutationGuards(bool $approval): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_mutations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_mutations_insert_guard');
        $types = $approval ? "'ticket_edit', 'ticket_status_change', 'ticket_approval'" : "'ticket_edit', 'ticket_status_change'";
        $targets = $approval ? "'todo', 'ready', 'blocked', 'cancelled', 'done'" : "'todo', 'blocked', 'cancelled', 'done'";
        $approvalOperation = $approval
            ? "OR (operation_type = 'ticket_approval' AND json_extract(operation_parameters_jcs, '$.status_operation') = 'approve')"
            : '';
        $guard = <<<SQL
                NEW.relative_path = '' OR length(NEW.relative_path) > 1024
                OR substr(NEW.relative_path, 1, 1) = '/' OR instr(NEW.relative_path, '\\') > 0
                OR instr('/' || NEW.relative_path || '/', '/../') > 0 OR instr('/' || NEW.relative_path || '/', '/./') > 0 OR instr(NEW.relative_path, '//') > 0
                OR length(NEW.expected_ticket_blob_sha) <> 64 OR NEW.expected_ticket_blob_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.base_content_sha256) <> 64 OR NEW.base_content_sha256 GLOB '*[^0-9a-f]*'
                OR length(NEW.target_contract_sha256) <> 64 OR NEW.target_contract_sha256 GLOB '*[^0-9a-f]*'
                OR length(NEW.expected_target_blob_sha) <> 64 OR NEW.expected_target_blob_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.expected_target_tree_oid) <> 64 OR NEW.expected_target_tree_oid GLOB '*[^0-9a-f]*'
                OR (NEW.source_contract_sha256 IS NOT NULL AND (length(NEW.source_contract_sha256) <> 64 OR NEW.source_contract_sha256 GLOB '*[^0-9a-f]*'))
                OR (NEW.prepared_commit_oid IS NOT NULL AND (length(NEW.prepared_commit_oid) <> 64 OR NEW.prepared_commit_oid GLOB '*[^0-9a-f]*'))
                OR ((NEW.prepared_commit_oid IS NULL) <> (NEW.prepared_attempt_token IS NULL)) OR (NEW.prepared_attempt_token IS NOT NULL AND NEW.prepared_attempt_token < 1)
                OR NEW.source_status NOT IN ('todo', 'ready', 'blocked', 'review') OR NEW.target_status NOT IN ($targets)
                OR NEW.audit_reason = '' OR json_valid(NEW.audit_redaction_matches) <> 1 OR json_type(NEW.audit_redaction_matches) <> 'array'
                OR NOT EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id
                    AND operation_type IN ($types) AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                    AND ((operation_type = 'ticket_edit' AND json_type(operation_parameters_jcs, '$.status_operation') IS NULL)
                      OR (operation_type = 'ticket_status_change' AND json_extract(operation_parameters_jcs, '$.status_operation') IN ('block', 'cancel', 'return_to_todo', 'complete_review'))
                      $approvalOperation))
        SQL;
        DB::unprepared("CREATE TRIGGER ticket_mutations_insert_guard BEFORE INSERT ON ticket_mutations WHEN $guard BEGIN SELECT RAISE(ABORT, 'invalid ticket mutation'); END");
        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_mutations_update_guard BEFORE UPDATE ON ticket_mutations
            WHEN NEW.status_operation_id <> OLD.status_operation_id OR NEW.relative_path <> OLD.relative_path
              OR NEW.expected_ticket_blob_sha <> OLD.expected_ticket_blob_sha OR NEW.base_content_sha256 <> OLD.base_content_sha256
              OR NEW.base_content <> OLD.base_content OR NEW.target_content <> OLD.target_content OR NEW.source_status <> OLD.source_status
              OR NEW.target_status <> OLD.target_status OR NEW.source_contract_sha256 IS NOT OLD.source_contract_sha256
              OR NEW.target_contract_sha256 <> OLD.target_contract_sha256 OR NEW.expected_target_blob_sha <> OLD.expected_target_blob_sha
              OR (NEW.expected_target_tree_oid <> OLD.expected_target_tree_oid AND NOT (OLD.expected_target_tree_oid = '0000000000000000000000000000000000000000000000000000000000000000'
                 AND NEW.expected_target_tree_oid <> '0000000000000000000000000000000000000000000000000000000000000000'
                 AND EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND phase = 'prepared' AND state = 'running')))
              OR NEW.expected_control_binding_version <> OLD.expected_control_binding_version OR NEW.audit_reason <> OLD.audit_reason
              OR json(NEW.audit_redaction_matches) <> json(OLD.audit_redaction_matches) OR NEW.external_completion_confirmed <> OLD.external_completion_confirmed
              OR NEW.commit_timestamp <> OLD.commit_timestamp
              OR ((NEW.prepared_commit_oid IS NOT OLD.prepared_commit_oid OR NEW.prepared_attempt_token IS NOT OLD.prepared_attempt_token) AND NOT (
                 OLD.prepared_commit_oid IS NULL AND OLD.prepared_attempt_token IS NULL AND NEW.prepared_commit_oid IS NOT NULL AND NEW.prepared_attempt_token IS NOT NULL
                 AND EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND state = 'running' AND phase = 'prepared' AND target_control_oid IS NULL)))
              OR $guard
            BEGIN SELECT RAISE(ABORT, 'invalid ticket mutation transition'); END
            SQL);
    }

    private function createApprovalGuards(): void
    {
        $hashes = ['reviewed_ticket_blob_sha', 'reviewed_control_sha', 'ticket_contract_sha256', 'config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash', 'approval_snapshot_hash'];
        $checks = array_map(static fn (string $field): string => "length(NEW.$field) <> 64 OR NEW.$field GLOB '*[^0-9a-f]*'", $hashes);
        $guard = implode("\n OR ", $checks).<<<'SQL'

 OR (NEW.approved_ticket_blob_sha IS NOT NULL AND (length(NEW.approved_ticket_blob_sha) <> 64 OR NEW.approved_ticket_blob_sha GLOB '*[^0-9a-f]*'))
 OR (NEW.approved_control_sha IS NOT NULL AND (length(NEW.approved_control_sha) <> 64 OR NEW.approved_control_sha GLOB '*[^0-9a-f]*'))
 OR ((NEW.approved_ticket_blob_sha IS NULL) <> (NEW.approved_control_sha IS NULL))
 OR NEW.saga_phase NOT IN ('prepared', 'commit_prepared', 'control_confirmed', 'complete', 'conflict')
 OR NEW.queue_state NOT IN ('pending_approval_effect', 'queued', 'consumed', 'cancelled')
 OR NEW.push_mode NOT IN ('manual', 'automatic_after_gates') OR NEW.version < 1
 OR json_valid(NEW.config_snapshot) <> 1 OR json_valid(NEW.scope_snapshot) <> 1 OR json_valid(NEW.prompt_snapshot) <> 1
 OR json_valid(NEW.instruction_snapshot) <> 1 OR json_valid(NEW.runtime_profile_snapshot) <> 1
 OR json_valid(NEW.agent_profile_snapshot) <> 1 OR json_valid(NEW.limits_snapshot) <> 1
 OR NOT EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND project_id = NEW.project_id
   AND operation_type = 'ticket_approval' AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path)
SQL;
        $insertGuard = $guard.<<<'SQL'

 OR NEW.id <> NEW.status_operation_id OR NEW.saga_phase <> 'prepared' OR NEW.intended_commit_sha IS NOT NULL
 OR NEW.approved_ticket_blob_sha IS NOT NULL OR NEW.approved_control_sha IS NOT NULL
 OR NEW.queue_state <> 'pending_approval_effect' OR NEW.version <> 1
 OR NOT EXISTS (SELECT 1 FROM control_operations AS operation
   JOIN ticket_mutations AS mutation ON mutation.status_operation_id = operation.id
   WHERE operation.id = NEW.status_operation_id
     AND operation.expected_control_commit = NEW.reviewed_control_sha
     AND mutation.expected_ticket_blob_sha = NEW.reviewed_ticket_blob_sha
     AND mutation.source_status = 'todo' AND mutation.target_status = 'ready'
     AND mutation.source_contract_sha256 = NEW.ticket_contract_sha256
     AND mutation.target_contract_sha256 = NEW.ticket_contract_sha256)
SQL;
        DB::unprepared("CREATE TRIGGER ticket_approvals_insert_guard BEFORE INSERT ON ticket_approvals WHEN $insertGuard BEGIN SELECT RAISE(ABORT, 'invalid ticket approval'); END");
        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_approvals_update_guard BEFORE UPDATE ON ticket_approvals
            WHEN NEW.id <> OLD.id OR NEW.project_id <> OLD.project_id OR NEW.status_operation_id <> OLD.status_operation_id
              OR NEW.ticket_id <> OLD.ticket_id OR NEW.relative_path <> OLD.relative_path
              OR NEW.reviewed_ticket_blob_sha <> OLD.reviewed_ticket_blob_sha OR NEW.reviewed_control_sha <> OLD.reviewed_control_sha
              OR NEW.ticket_contract_sha256 <> OLD.ticket_contract_sha256 OR NEW.control_generation <> OLD.control_generation
              OR NEW.snapshot_context_id <> OLD.snapshot_context_id
              OR json(NEW.config_snapshot) <> json(OLD.config_snapshot) OR NEW.config_hash <> OLD.config_hash
              OR json(NEW.scope_snapshot) <> json(OLD.scope_snapshot) OR NEW.scope_hash <> OLD.scope_hash
              OR json(NEW.prompt_snapshot) <> json(OLD.prompt_snapshot) OR NEW.prompt_hash <> OLD.prompt_hash
              OR json(NEW.instruction_snapshot) <> json(OLD.instruction_snapshot) OR NEW.instruction_hash <> OLD.instruction_hash
              OR json(NEW.runtime_profile_snapshot) <> json(OLD.runtime_profile_snapshot) OR NEW.runtime_profile_hash <> OLD.runtime_profile_hash
              OR json(NEW.agent_profile_snapshot) <> json(OLD.agent_profile_snapshot) OR NEW.agent_profile_hash <> OLD.agent_profile_hash
              OR NEW.security_policy_hash <> OLD.security_policy_hash OR json(NEW.limits_snapshot) <> json(OLD.limits_snapshot)
              OR NEW.approval_snapshot_hash <> OLD.approval_snapshot_hash OR NEW.attention_user_id IS NOT OLD.attention_user_id
              OR NEW.push_mode <> OLD.push_mode OR NEW.approved_by <> OLD.approved_by OR NEW.approved_at <> OLD.approved_at
              OR NEW.created_at <> OLD.created_at
              OR NEW.version <> OLD.version + 1
              OR NOT (
                (OLD.saga_phase = 'prepared' AND NEW.saga_phase = 'commit_prepared'
                  AND OLD.intended_commit_sha IS NULL AND NEW.intended_commit_sha IS NOT NULL
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND NEW.queue_state = OLD.queue_state)
                OR (OLD.saga_phase = 'commit_prepared' AND NEW.saga_phase = 'control_confirmed'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND NEW.queue_state = OLD.queue_state)
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha IS NOT NULL
                  AND NEW.approved_ticket_blob_sha IS NOT NULL AND NEW.approved_control_sha = NEW.intended_commit_sha
                  AND OLD.approved_ticket_blob_sha IS NULL AND OLD.approved_control_sha IS NULL
                  AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'queued')
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND OLD.queue_state = 'queued' AND NEW.queue_state IN ('consumed', 'cancelled'))
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'conflict'
                  AND NEW.intended_commit_sha IS OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'cancelled')
              )
              OR $guard
            BEGIN SELECT RAISE(ABORT, 'invalid ticket approval transition'); END
            SQL);
    }

    private function trigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! property_exists($row, 'sql') || ! is_string($row->sql)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }

        return $row->sql;
    }
};
