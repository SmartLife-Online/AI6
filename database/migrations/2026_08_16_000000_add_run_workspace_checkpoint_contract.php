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
            $table->string('run_branch')->nullable()->unique()->after('run_base_sha');
            $table->string('worktree_path')->nullable()->unique()->after('run_branch');
            $table->char('checkpoint_commit_sha', 64)->nullable()->after('worktree_path');
            $table->char('checkpoint_tree_sha', 64)->nullable()->after('checkpoint_commit_sha');
            $table->char('checkpoint_diff_hash', 64)->nullable()->after('checkpoint_tree_sha');
        });

        DB::unprepared('DROP TRIGGER runs_update_guard');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_update_guard BEFORE UPDATE ON runs
            WHEN NEW.project_id <> OLD.project_id OR NEW.ticket_approval_id <> OLD.ticket_approval_id
              OR NEW.status_operation_id <> OLD.status_operation_id OR NEW.claim_parent_control_sha <> OLD.claim_parent_control_sha
              OR NEW.initial_run_base_sha <> OLD.initial_run_base_sha OR NEW.version <> OLD.version + 1
              OR NEW.state NOT IN ('queued', 'running', 'waiting', 'failed', 'completed', 'cancelled')
              OR NEW.phase NOT IN ('prepare', 'implement', 'check', 'review', 'fix', 'finalize', 'security_review', 'publish')
              OR (NEW.wait_reason IS NOT NULL AND NEW.wait_reason NOT IN ('human_question', 'scope_approval', 'contract_change', 'review_limit', 'resource_limit', 'provider_error', 'invalid_json', 'check_failure', 'manual_gate', 'security_gate', 'git_base_changed', 'git_conflict', 'manual_push', 'manual_report', 'status_sync'))
              OR length(NEW.run_base_sha) <> 64 OR NEW.run_base_sha GLOB '*[^0-9a-f]*'
              OR ((NEW.state = 'waiting') <> (NEW.wait_reason IS NOT NULL))
              OR ((NEW.run_branch IS NULL) <> (NEW.worktree_path IS NULL))
              OR (NEW.run_branch IS NOT NULL AND (length(NEW.run_branch) <> 89 OR NEW.run_branch NOT GLOB 'refs/heads/ai6/runs/[0-9a-f]*' OR NEW.run_branch GLOB '*[^A-Za-z0-9/._-]*'))
              OR (OLD.run_branch IS NOT NULL AND NEW.run_branch IS NOT OLD.run_branch)
              OR (OLD.worktree_path IS NOT NULL AND NEW.worktree_path IS NOT OLD.worktree_path)
              OR ((NEW.checkpoint_commit_sha IS NULL) <> (NEW.checkpoint_tree_sha IS NULL))
              OR ((NEW.checkpoint_commit_sha IS NULL) <> (NEW.checkpoint_diff_hash IS NULL))
              OR (NEW.checkpoint_commit_sha IS NOT NULL AND (length(NEW.checkpoint_commit_sha) <> 64 OR NEW.checkpoint_commit_sha GLOB '*[^0-9a-f]*'
                  OR length(NEW.checkpoint_tree_sha) <> 64 OR NEW.checkpoint_tree_sha GLOB '*[^0-9a-f]*'
                  OR length(NEW.checkpoint_diff_hash) <> 64 OR NEW.checkpoint_diff_hash GLOB '*[^0-9a-f]*'))
              OR (OLD.checkpoint_commit_sha IS NOT NULL AND (NEW.checkpoint_commit_sha IS NOT OLD.checkpoint_commit_sha
                  OR NEW.checkpoint_tree_sha IS NOT OLD.checkpoint_tree_sha OR NEW.checkpoint_diff_hash IS NOT OLD.checkpoint_diff_hash))
            BEGIN SELECT RAISE(ABORT, 'invalid run transition'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS runs_update_guard');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_update_guard BEFORE UPDATE ON runs
            WHEN NEW.project_id <> OLD.project_id OR NEW.ticket_approval_id <> OLD.ticket_approval_id
              OR NEW.status_operation_id <> OLD.status_operation_id OR NEW.claim_parent_control_sha <> OLD.claim_parent_control_sha
              OR NEW.initial_run_base_sha <> OLD.initial_run_base_sha OR NEW.version <> OLD.version + 1
              OR NEW.state NOT IN ('queued', 'running', 'waiting', 'failed', 'completed', 'cancelled')
              OR NEW.phase NOT IN ('prepare', 'implement', 'check', 'review', 'fix', 'finalize', 'security_review', 'publish')
              OR (NEW.wait_reason IS NOT NULL AND NEW.wait_reason NOT IN ('human_question', 'scope_approval', 'contract_change', 'review_limit', 'resource_limit', 'provider_error', 'invalid_json', 'check_failure', 'manual_gate', 'security_gate', 'git_base_changed', 'git_conflict', 'manual_push', 'manual_report', 'status_sync'))
              OR length(NEW.run_base_sha) <> 64 OR NEW.run_base_sha GLOB '*[^0-9a-f]*'
              OR ((NEW.state = 'waiting') <> (NEW.wait_reason IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'invalid run transition'); END
            SQL);
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropUnique(['run_branch']);
            $table->dropUnique(['worktree_path']);
            $table->dropColumn(['run_branch', 'worktree_path', 'checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash']);
        });
    }
};
