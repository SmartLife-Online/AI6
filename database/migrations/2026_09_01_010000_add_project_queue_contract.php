<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_approvals_update_guard');

        Schema::table('ticket_approvals', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('queue_state');
            $table->index(['project_id', 'queue_state', 'queued_at', 'id'], 'ticket_approvals_project_queue');
        });

        DB::table('ticket_approvals')
            ->where('saga_phase', 'complete')
            ->where('queue_state', 'queued')
            ->update(['queue_state' => 'available', 'queued_at' => null]);

        $this->createQueueGuard();
    }

    public function down(): void
    {
        if (DB::table('ticket_approvals')->where('queue_state', 'available')->exists()) {
            throw new RuntimeException('The legacy approval schema cannot represent available queue entries.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_approvals_update_guard');

        Schema::table('ticket_approvals', function (Blueprint $table): void {
            $table->dropIndex('ticket_approvals_project_queue');
            $table->dropColumn('queued_at');
        });

        $this->createLegacyGuard();
    }

    private function createQueueGuard(): void
    {
        $guard = $this->commonGuard("'pending_approval_effect', 'available', 'queued', 'consumed', 'cancelled'");
        $immutable = $this->immutableColumns();

        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_approvals_update_guard BEFORE UPDATE ON ticket_approvals
            WHEN {$immutable}
              OR NEW.version <> OLD.version + 1
              OR NOT (
                (OLD.saga_phase = 'prepared' AND NEW.saga_phase = 'commit_prepared'
                  AND OLD.intended_commit_sha IS NULL AND NEW.intended_commit_sha IS NOT NULL
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND NEW.queue_state = OLD.queue_state AND NEW.queued_at IS OLD.queued_at)
                OR (OLD.saga_phase = 'commit_prepared' AND NEW.saga_phase = 'control_confirmed'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND NEW.queue_state = OLD.queue_state AND NEW.queued_at IS OLD.queued_at)
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha IS NOT NULL
                  AND NEW.approved_ticket_blob_sha IS NOT NULL AND NEW.approved_control_sha = NEW.intended_commit_sha
                  AND OLD.approved_ticket_blob_sha IS NULL AND OLD.approved_control_sha IS NULL
                  AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'available'
                  AND OLD.queued_at IS NULL AND NEW.queued_at IS NULL)
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND OLD.queue_state = 'available' AND NEW.queue_state = 'queued'
                  AND OLD.queued_at IS NULL AND NEW.queued_at IS NOT NULL)
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND OLD.queue_state = 'queued' AND NEW.queue_state = 'queued'
                  AND OLD.queued_at IS NOT NULL AND NEW.queued_at IS NOT NULL AND NEW.queued_at <> OLD.queued_at)
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND OLD.queue_state = 'queued' AND NEW.queue_state = 'available'
                  AND OLD.queued_at IS NOT NULL AND NEW.queued_at IS NULL)
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND ((OLD.queue_state = 'queued' AND NEW.queue_state IN ('consumed', 'cancelled') AND NEW.queued_at IS OLD.queued_at)
                    OR (OLD.queue_state = 'available' AND NEW.queue_state = 'cancelled' AND NEW.queued_at IS NULL)))
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'conflict'
                  AND NEW.intended_commit_sha IS OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha IS NULL AND NEW.approved_control_sha IS NULL
                  AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'cancelled'
                  AND OLD.queued_at IS NULL AND NEW.queued_at IS NULL)
              )
              OR {$guard}
            BEGIN SELECT RAISE(ABORT, 'invalid ticket approval transition'); END
            SQL);
    }

    private function createLegacyGuard(): void
    {
        $guard = $this->commonGuard("'pending_approval_effect', 'queued', 'consumed', 'cancelled'");
        $immutable = $this->immutableColumns();

        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_approvals_update_guard BEFORE UPDATE ON ticket_approvals
            WHEN {$immutable}
              OR NEW.version <> OLD.version + 1
              OR NOT (
                (OLD.saga_phase = 'prepared' AND NEW.saga_phase = 'commit_prepared' AND OLD.intended_commit_sha IS NULL
                  AND NEW.intended_commit_sha IS NOT NULL AND NEW.approved_ticket_blob_sha IS NULL
                  AND NEW.approved_control_sha IS NULL AND NEW.queue_state = OLD.queue_state)
                OR (OLD.saga_phase = 'commit_prepared' AND NEW.saga_phase = 'control_confirmed'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha AND NEW.approved_ticket_blob_sha IS NULL
                  AND NEW.approved_control_sha IS NULL AND NEW.queue_state = OLD.queue_state)
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha IS NOT NULL AND NEW.approved_ticket_blob_sha IS NOT NULL
                  AND NEW.approved_control_sha = NEW.intended_commit_sha AND OLD.approved_ticket_blob_sha IS NULL
                  AND OLD.approved_control_sha IS NULL AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'queued')
                OR (OLD.saga_phase = 'complete' AND NEW.saga_phase = 'complete'
                  AND NEW.intended_commit_sha = OLD.intended_commit_sha
                  AND NEW.approved_ticket_blob_sha = OLD.approved_ticket_blob_sha
                  AND NEW.approved_control_sha = OLD.approved_control_sha
                  AND OLD.queue_state = 'queued' AND NEW.queue_state IN ('consumed', 'cancelled'))
                OR (OLD.saga_phase IN ('prepared', 'commit_prepared', 'control_confirmed') AND NEW.saga_phase = 'conflict'
                  AND NEW.intended_commit_sha IS OLD.intended_commit_sha AND NEW.approved_ticket_blob_sha IS NULL
                  AND NEW.approved_control_sha IS NULL AND OLD.queue_state = 'pending_approval_effect' AND NEW.queue_state = 'cancelled')
              )
              OR {$guard}
            BEGIN SELECT RAISE(ABORT, 'invalid ticket approval transition'); END
            SQL);
    }

    private function immutableColumns(): string
    {
        return <<<'SQL'
NEW.id <> OLD.id OR NEW.project_id <> OLD.project_id OR NEW.status_operation_id <> OLD.status_operation_id
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
SQL;
    }

    private function commonGuard(string $states): string
    {
        $hashes = ['reviewed_ticket_blob_sha', 'reviewed_control_sha', 'ticket_contract_sha256', 'config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash', 'approval_snapshot_hash'];
        $checks = array_map(static fn (string $field): string => "length(NEW.$field) <> 64 OR NEW.$field GLOB '*[^0-9a-f]*'", $hashes);

        return implode("\n OR ", $checks).<<<SQL

 OR (NEW.approved_ticket_blob_sha IS NOT NULL AND (length(NEW.approved_ticket_blob_sha) <> 64 OR NEW.approved_ticket_blob_sha GLOB '*[^0-9a-f]*'))
 OR (NEW.approved_control_sha IS NOT NULL AND (length(NEW.approved_control_sha) <> 64 OR NEW.approved_control_sha GLOB '*[^0-9a-f]*'))
 OR ((NEW.approved_ticket_blob_sha IS NULL) <> (NEW.approved_control_sha IS NULL))
 OR NEW.saga_phase NOT IN ('prepared', 'commit_prepared', 'control_confirmed', 'complete', 'conflict')
 OR NEW.queue_state NOT IN ($states)
 OR NEW.push_mode NOT IN ('manual', 'automatic_after_gates') OR NEW.version < 1
 OR json_valid(NEW.config_snapshot) <> 1 OR json_valid(NEW.scope_snapshot) <> 1 OR json_valid(NEW.prompt_snapshot) <> 1
 OR json_valid(NEW.instruction_snapshot) <> 1 OR json_valid(NEW.runtime_profile_snapshot) <> 1
 OR json_valid(NEW.agent_profile_snapshot) <> 1 OR json_valid(NEW.limits_snapshot) <> 1
 OR NOT EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND project_id = NEW.project_id
   AND operation_type = 'ticket_approval' AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path)
SQL;
    }
};
