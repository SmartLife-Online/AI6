<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_ARTIFACTS = "'implementation_summary', 'provider_raw', 'limit_pending', 'limit_grant', 'quarantined_path'";

    private const NEW_ARTIFACTS = "'implementation_summary', 'provider_raw', 'limit_pending', 'limit_grant', 'quarantined_path', 'context_package', 'completion_report'";

    private const OLD_WORKSPACE_CLAUSE = 'OR ((NEW.run_branch IS NULL) <> (NEW.worktree_path IS NULL))';

    private const NEW_WORKSPACE_CLAUSE = "OR (NEW.run_type = 'implementation' AND ((NEW.run_branch IS NULL) <> (NEW.worktree_path IS NULL)))\n              OR (NEW.run_type = 'review_only' AND NEW.run_branch IS NOT NULL)";

    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('review_subject_kind')->nullable()->after('completion_mode');
            $table->char('review_subject_base_sha', 64)->nullable()->after('review_subject_kind');
            $table->char('review_subject_source_sha', 64)->nullable()->after('review_subject_base_sha');
            $table->char('review_workspace_hash', 64)->nullable()->after('review_subject_source_sha');
        });
        $this->rewriteTrigger('runs_update_guard', self::OLD_WORKSPACE_CLAUSE, self::NEW_WORKSPACE_CLAUSE);
        $this->rewriteTrigger(
            'run_artifacts_insert_guard',
            'NEW.kind NOT IN ('.self::OLD_ARTIFACTS.')',
            'NEW.kind NOT IN ('.self::NEW_ARTIFACTS.')',
        );
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_execution_insert_guard BEFORE INSERT ON runs
            WHEN ((NEW.review_subject_kind IS NULL) <> (NEW.review_subject_base_sha IS NULL))
              OR ((NEW.review_subject_kind IS NULL) <> (NEW.review_subject_source_sha IS NULL))
              OR ((NEW.review_subject_kind IS NULL) <> (NEW.review_workspace_hash IS NULL))
              OR (NEW.review_subject_kind IS NOT NULL AND NEW.run_type <> 'review_only')
              OR (NEW.review_subject_kind IS NOT NULL AND NEW.review_subject_kind NOT IN ('managed_branch', 'commit_range', 'single_commit', 'validated_patch', 'checkpoint'))
              OR (NEW.review_subject_base_sha IS NOT NULL AND (length(NEW.review_subject_base_sha) <> 64 OR NEW.review_subject_base_sha GLOB '*[^0-9a-f]*'))
              OR (NEW.review_subject_source_sha IS NOT NULL AND (length(NEW.review_subject_source_sha) <> 64 OR NEW.review_subject_source_sha GLOB '*[^0-9a-f]*'))
              OR (NEW.review_workspace_hash IS NOT NULL AND (length(NEW.review_workspace_hash) <> 64 OR NEW.review_workspace_hash GLOB '*[^0-9a-f]*'))
            BEGIN SELECT RAISE(ABORT, 'invalid review-only execution binding'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_execution_update_guard BEFORE UPDATE ON runs
            WHEN ((NEW.review_subject_kind IS NULL) <> (NEW.review_subject_base_sha IS NULL))
              OR ((NEW.review_subject_kind IS NULL) <> (NEW.review_subject_source_sha IS NULL))
              OR ((NEW.review_subject_kind IS NULL) <> (NEW.review_workspace_hash IS NULL))
              OR (NEW.review_subject_kind IS NOT NULL AND NEW.run_type <> 'review_only')
              OR (NEW.review_subject_kind IS NOT NULL AND NEW.review_subject_kind NOT IN ('managed_branch', 'commit_range', 'single_commit', 'validated_patch', 'checkpoint'))
              OR (NEW.review_subject_base_sha IS NOT NULL AND (length(NEW.review_subject_base_sha) <> 64 OR NEW.review_subject_base_sha GLOB '*[^0-9a-f]*'))
              OR (NEW.review_subject_source_sha IS NOT NULL AND (length(NEW.review_subject_source_sha) <> 64 OR NEW.review_subject_source_sha GLOB '*[^0-9a-f]*'))
              OR (NEW.review_workspace_hash IS NOT NULL AND (length(NEW.review_workspace_hash) <> 64 OR NEW.review_workspace_hash GLOB '*[^0-9a-f]*'))
              OR (OLD.review_subject_kind IS NOT NULL AND (NEW.review_subject_kind IS NOT OLD.review_subject_kind
                  OR NEW.review_subject_base_sha IS NOT OLD.review_subject_base_sha
                  OR NEW.review_subject_source_sha IS NOT OLD.review_subject_source_sha
                  OR NEW.review_workspace_hash IS NOT OLD.review_workspace_hash))
            BEGIN SELECT RAISE(ABORT, 'invalid review-only execution binding'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_execution_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_execution_insert_guard');
        $this->rewriteTrigger(
            'run_artifacts_insert_guard',
            'NEW.kind NOT IN ('.self::NEW_ARTIFACTS.')',
            'NEW.kind NOT IN ('.self::OLD_ARTIFACTS.')',
        );
        $this->rewriteTrigger('runs_update_guard', self::NEW_WORKSPACE_CLAUSE, self::OLD_WORKSPACE_CLAUSE);
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn([
                'review_subject_kind',
                'review_subject_base_sha',
                'review_subject_source_sha',
                'review_workspace_hash',
            ]);
        });
    }

    private function rewriteTrigger(string $name, string $old, string $new): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }
        $sql = str_replace($old, $new, $row->sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The published SQLite guard '.$name.' could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
