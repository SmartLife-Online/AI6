<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE UNIQUE INDEX run_agents_unique_bound_session ON run_agents (session_id) WHERE session_id IS NOT NULL');
        Schema::create('review_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->unsignedInteger('round_number');
            $table->uuid('slot_id');
            $table->unsignedInteger('attempt');
            $table->string('role');
            $table->string('provider_profile');
            $table->string('model');
            $table->string('effort');
            $table->string('prompt_profile');
            $table->uuid('session_id');
            $table->char('checkpoint_commit_sha', 64);
            $table->char('checkpoint_tree_sha', 64);
            $table->char('diff_hash', 64);
            $table->char('approval_config_hash', 64);
            $table->char('approval_scope_hash', 64);
            $table->char('approval_prompt_hash', 64);
            $table->char('approval_instruction_hash', 64);
            $table->char('approval_runtime_profile_hash', 64);
            $table->char('approval_agent_profile_hash', 64);
            $table->char('approval_security_policy_hash', 64);
            $table->char('approval_snapshot_hash', 64);
            $table->char('slot_prompt_hash', 64)->nullable();
            $table->char('slot_instruction_hash', 64)->nullable();
            $table->char('slot_runtime_profile_hash', 64)->nullable();
            $table->char('workspace_tree_hash', 64)->nullable();
            $table->string('invocation_outcome');
            $table->string('failure_code')->nullable();
            $table->string('result_status')->nullable();
            $table->foreignUuid('raw_artifact_id')->nullable()->constrained('run_artifacts')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['run_id', 'round_number', 'slot_id', 'attempt']);
            $table->index(['run_id', 'round_number', 'slot_id', 'invocation_outcome'], 'review_results_progress_index');
        });
        DB::unprepared("CREATE UNIQUE INDEX review_results_one_valid_result ON review_results (run_id, round_number, slot_id) WHERE invocation_outcome = 'valid_result'");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_results_insert_guard BEFORE INSERT ON review_results
            WHEN NEW.round_number < 1 OR NEW.attempt < 1 OR NEW.role <> 'quality_review'
              OR NEW.provider_profile = '' OR NEW.model = '' OR NEW.effort = '' OR NEW.prompt_profile = ''
              OR NEW.invocation_outcome NOT IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json', 'binding_error', 'checkpoint_error', 'workspace_error', 'human_request_error')
              OR (NEW.invocation_outcome = 'valid_result' AND (NEW.result_status NOT IN ('nothing_to_fix', 'findings_to_fix') OR NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome = 'needs_human' AND (NEW.result_status <> 'needs_human' OR NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome NOT IN ('valid_result', 'needs_human') AND (NEW.failure_code IS NULL OR NEW.result_status IS NOT NULL))
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_instruction_hash IS NULL)
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_runtime_profile_hash IS NULL)
              OR (NEW.workspace_tree_hash IS NULL AND NEW.invocation_outcome IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json'))
              OR length(NEW.checkpoint_commit_sha) <> 64 OR NEW.checkpoint_commit_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.checkpoint_tree_sha) <> 64 OR NEW.checkpoint_tree_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.diff_hash) <> 64 OR NEW.diff_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_config_hash) <> 64 OR NEW.approval_config_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_scope_hash) <> 64 OR NEW.approval_scope_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_prompt_hash) <> 64 OR NEW.approval_prompt_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_instruction_hash) <> 64 OR NEW.approval_instruction_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_runtime_profile_hash) <> 64 OR NEW.approval_runtime_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_agent_profile_hash) <> 64 OR NEW.approval_agent_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_security_policy_hash) <> 64 OR NEW.approval_security_policy_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_snapshot_hash) <> 64 OR NEW.approval_snapshot_hash GLOB '*[^0-9a-f]*'
              OR (NEW.slot_prompt_hash IS NOT NULL AND (length(NEW.slot_prompt_hash) <> 64 OR NEW.slot_prompt_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_instruction_hash IS NOT NULL AND (length(NEW.slot_instruction_hash) <> 64 OR NEW.slot_instruction_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_runtime_profile_hash IS NOT NULL AND (length(NEW.slot_runtime_profile_hash) <> 64 OR NEW.slot_runtime_profile_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.workspace_tree_hash IS NOT NULL AND (length(NEW.workspace_tree_hash) <> 64 OR NEW.workspace_tree_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.raw_artifact_id IS NOT NULL AND NOT EXISTS (
                  SELECT 1 FROM run_artifacts artifact
                  WHERE artifact.id = NEW.raw_artifact_id AND artifact.run_id = NEW.run_id AND artifact.kind = 'provider_raw'
              ))
            BEGIN SELECT RAISE(ABORT, 'invalid review result'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_results_update_guard BEFORE UPDATE ON review_results
            BEGIN SELECT RAISE(ABORT, 'review results are immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_results_delete_guard BEFORE DELETE ON review_results
            BEGIN SELECT RAISE(ABORT, 'review results are immutable'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_insert_guard');
        DB::unprepared('DROP INDEX IF EXISTS review_results_one_valid_result');
        Schema::dropIfExists('review_results');
        DB::unprepared('DROP INDEX IF EXISTS run_agents_unique_bound_session');
    }
};
