<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->char('candidate_tree_sha', 64)->nullable();
            $table->char('candidate_diff_hash', 64)->nullable();
            $table->char('candidate_base_sha', 64)->nullable();
            $table->char('candidate_checkpoint_commit_sha', 64)->nullable();
            $table->char('candidate_ticket_contract_sha256', 64)->nullable();
            $table->char('candidate_approval_snapshot_hash', 64)->nullable();
            $table->unsignedBigInteger('candidate_evidence_epoch')->nullable();
            $table->char('candidate_scope_hash', 64)->nullable();
            $table->char('candidate_config_hash', 64)->nullable();
            $table->char('candidate_prompt_hash', 64)->nullable();
            $table->char('candidate_security_policy_hash', 64)->nullable();
            $table->timestamp('candidate_bound_at')->nullable();
            $table->timestamp('candidate_invalidated_at')->nullable();
        });
        Schema::table('run_gates', function (Blueprint $table): void {
            $table->char('evidence_candidate_tree_sha', 64)->nullable();
            $table->char('evidence_candidate_diff_hash', 64)->nullable();
            $table->unsignedBigInteger('evidence_expected_run_version')->nullable();
            $table->string('evidence_source', 255)->nullable();
            $table->timestamp('evidence_observed_at')->nullable();
            $table->char('evidence_digest', 64)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_candidate_insert_guard BEFORE INSERT ON runs
            WHEN NEW.candidate_tree_sha IS NOT NULL OR NEW.candidate_diff_hash IS NOT NULL
              OR NEW.candidate_base_sha IS NOT NULL OR NEW.candidate_checkpoint_commit_sha IS NOT NULL
              OR NEW.candidate_ticket_contract_sha256 IS NOT NULL OR NEW.candidate_approval_snapshot_hash IS NOT NULL
              OR NEW.candidate_evidence_epoch IS NOT NULL OR NEW.candidate_scope_hash IS NOT NULL
              OR NEW.candidate_config_hash IS NOT NULL OR NEW.candidate_prompt_hash IS NOT NULL
              OR NEW.candidate_security_policy_hash IS NOT NULL OR NEW.candidate_bound_at IS NOT NULL
              OR NEW.candidate_invalidated_at IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'invalid initial candidate binding'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_candidate_update_guard BEFORE UPDATE ON runs
            WHEN
              ((NEW.candidate_tree_sha IS NULL OR NEW.candidate_diff_hash IS NULL
                OR NEW.candidate_base_sha IS NULL OR NEW.candidate_checkpoint_commit_sha IS NULL
                OR NEW.candidate_ticket_contract_sha256 IS NULL OR NEW.candidate_approval_snapshot_hash IS NULL
                OR NEW.candidate_evidence_epoch IS NULL OR NEW.candidate_scope_hash IS NULL
                OR NEW.candidate_config_hash IS NULL OR NEW.candidate_prompt_hash IS NULL
                OR NEW.candidate_security_policy_hash IS NULL OR NEW.candidate_bound_at IS NULL)
               AND NOT (NEW.candidate_tree_sha IS NULL AND NEW.candidate_diff_hash IS NULL
                AND NEW.candidate_base_sha IS NULL AND NEW.candidate_checkpoint_commit_sha IS NULL
                AND NEW.candidate_ticket_contract_sha256 IS NULL AND NEW.candidate_approval_snapshot_hash IS NULL
                AND NEW.candidate_evidence_epoch IS NULL AND NEW.candidate_scope_hash IS NULL
                AND NEW.candidate_config_hash IS NULL AND NEW.candidate_prompt_hash IS NULL
                AND NEW.candidate_security_policy_hash IS NULL AND NEW.candidate_bound_at IS NULL
                AND NEW.candidate_invalidated_at IS NULL))
              OR (OLD.candidate_tree_sha IS NOT NULL AND NEW.candidate_tree_sha IS NULL)
              OR (NEW.candidate_tree_sha IS NOT NULL AND (
                length(NEW.candidate_tree_sha) <> 64 OR NEW.candidate_tree_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_diff_hash) <> 64 OR NEW.candidate_diff_hash GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_base_sha) <> 64 OR NEW.candidate_base_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_checkpoint_commit_sha) <> 64 OR NEW.candidate_checkpoint_commit_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_ticket_contract_sha256) <> 64 OR NEW.candidate_ticket_contract_sha256 GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_approval_snapshot_hash) <> 64 OR NEW.candidate_approval_snapshot_hash GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_scope_hash) <> 64 OR NEW.candidate_scope_hash GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_config_hash) <> 64 OR NEW.candidate_config_hash GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_prompt_hash) <> 64 OR NEW.candidate_prompt_hash GLOB '*[^0-9a-f]*'
                OR length(NEW.candidate_security_policy_hash) <> 64 OR NEW.candidate_security_policy_hash GLOB '*[^0-9a-f]*'
                OR (NEW.candidate_invalidated_at IS NULL AND (
                  NEW.candidate_base_sha <> NEW.run_base_sha
                  OR NEW.candidate_checkpoint_commit_sha <> NEW.checkpoint_commit_sha
                  OR NEW.candidate_evidence_epoch <> NEW.evidence_epoch
                  OR NEW.candidate_scope_hash <> COALESCE(NEW.effective_scope_hash, NEW.scope_hash)
                  OR NEW.candidate_config_hash <> NEW.config_hash
                  OR NEW.candidate_prompt_hash <> NEW.prompt_hash
                  OR NEW.candidate_security_policy_hash <> NEW.security_policy_hash
                ))
              ))
              OR (OLD.candidate_tree_sha IS NOT NULL AND OLD.candidate_invalidated_at IS NULL
                AND (NEW.candidate_tree_sha <> OLD.candidate_tree_sha
                  OR NEW.candidate_diff_hash <> OLD.candidate_diff_hash
                  OR NEW.candidate_base_sha <> OLD.candidate_base_sha)
                AND NEW.candidate_invalidated_at IS NULL)
            BEGIN SELECT RAISE(ABORT, 'invalid candidate binding transition'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_gates_candidate_insert_guard BEFORE INSERT ON run_gates
            WHEN NEW.evidence_candidate_tree_sha IS NOT NULL
              OR NEW.evidence_candidate_diff_hash IS NOT NULL
              OR NEW.evidence_expected_run_version IS NOT NULL
              OR NEW.evidence_source IS NOT NULL OR NEW.evidence_observed_at IS NOT NULL
              OR NEW.evidence_digest IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'invalid initial candidate gate evidence'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_gates_candidate_update_guard BEFORE UPDATE ON run_gates
            WHEN ((NEW.evidence_candidate_tree_sha IS NULL) <> (NEW.evidence_candidate_diff_hash IS NULL))
              OR ((NEW.evidence_candidate_tree_sha IS NULL) <> (NEW.evidence_expected_run_version IS NULL))
              OR (NEW.evidence_candidate_tree_sha IS NOT NULL AND (
                length(NEW.evidence_candidate_tree_sha) <> 64 OR NEW.evidence_candidate_tree_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.evidence_candidate_diff_hash) <> 64 OR NEW.evidence_candidate_diff_hash GLOB '*[^0-9a-f]*'
                OR NEW.evidence_expected_run_version < 1
              ))
              OR (NEW.state = 'closed' AND (NEW.evidence_source IS NULL OR NEW.evidence_observed_at IS NULL))
              OR (NEW.evidence_digest IS NOT NULL AND (
                length(NEW.evidence_digest) <> 64 OR NEW.evidence_digest GLOB '*[^0-9a-f]*'
              ))
            BEGIN SELECT RAISE(ABORT, 'invalid candidate gate evidence transition'); END
            SQL);
        $this->extendInterventionWaitReasons(true);
    }

    public function down(): void
    {
        $this->extendInterventionWaitReasons(false);
        DB::unprepared('DROP TRIGGER IF EXISTS run_gates_candidate_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_gates_candidate_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_candidate_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_candidate_insert_guard');
        Schema::table('run_gates', function (Blueprint $table): void {
            $table->dropColumn([
                'evidence_candidate_tree_sha', 'evidence_candidate_diff_hash', 'evidence_expected_run_version',
                'evidence_source', 'evidence_observed_at', 'evidence_digest',
            ]);
        });
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn([
                'candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha',
                'candidate_checkpoint_commit_sha', 'candidate_ticket_contract_sha256',
                'candidate_approval_snapshot_hash', 'candidate_evidence_epoch', 'candidate_scope_hash',
                'candidate_config_hash', 'candidate_prompt_hash', 'candidate_security_policy_hash',
                'candidate_bound_at', 'candidate_invalidated_at',
            ]);
        });
    }

    private function extendInterventionWaitReasons(bool $include): void
    {
        $name = 'interventions_audit_insert_guard';
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published intervention audit guard is missing.');
        }
        $old = $include
            ? "'manual_report','status_sync'"
            : "'manual_report','status_sync','manual_gate'";
        $new = $include
            ? "'manual_report','status_sync','manual_gate'"
            : "'manual_report','status_sync'";
        $sql = str_replace($old, $new, $row->sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The intervention audit guard could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
