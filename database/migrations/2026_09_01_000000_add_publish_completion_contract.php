<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $objects = $this->sqliteObjects('runs');
        Schema::table('runs', function (Blueprint $table): void {
            $table->char('final_commit_oid', 64)->nullable();
            $table->string('final_commit_kind')->nullable();
            $table->char('final_commit_tree_oid', 64)->nullable();
            $table->char('final_commit_parent_oid', 64)->nullable();
            $table->char('branch_publication_target_oid', 64)->nullable();
            $table->unsignedBigInteger('final_commit_timestamp')->nullable();
            $table->char('branch_publication_expected_oid', 64)->nullable();
            $table->string('branch_publication_state')->nullable();
            $table->timestamp('branch_publication_confirmed_at')->nullable();
            $table->char('recorded_scope_sha256', 64)->nullable();
        });
        $this->restoreSqliteObjects($objects);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_publish_completion_update_guard BEFORE UPDATE ON runs
            WHEN (NEW.branch_publication_state IS NOT NULL AND NEW.branch_publication_state NOT IN ('preparing','prepared','confirmed'))
              OR ((NEW.final_commit_oid IS NULL) <> (NEW.final_commit_tree_oid IS NULL))
              OR ((NEW.final_commit_oid IS NULL) <> (NEW.final_commit_parent_oid IS NULL))
              OR ((NEW.final_commit_kind IS NULL) <> (NEW.branch_publication_target_oid IS NULL))
              OR (NEW.final_commit_kind IS NOT NULL AND NEW.final_commit_kind NOT IN ('created','no_change'))
              OR (NEW.branch_publication_target_oid IS NOT NULL AND (
                    length(NEW.branch_publication_target_oid) <> 64
                    OR NEW.branch_publication_target_oid GLOB '*[^0-9a-f]*'
                ))
              OR (NEW.final_commit_kind = 'created' AND (
                    NEW.final_commit_oid IS NULL OR NEW.branch_publication_target_oid <> NEW.final_commit_oid
                ))
              OR (NEW.final_commit_kind = 'no_change' AND (
                    NEW.final_commit_oid IS NOT NULL OR NEW.final_commit_tree_oid IS NOT NULL
                    OR NEW.final_commit_parent_oid IS NOT NULL OR NEW.branch_publication_target_oid <> NEW.candidate_base_sha
                ))
              OR (NEW.final_commit_oid IS NOT NULL AND (
                    length(NEW.final_commit_oid) <> 64 OR NEW.final_commit_oid GLOB '*[^0-9a-f]*'
                    OR length(NEW.final_commit_tree_oid) <> 64 OR NEW.final_commit_tree_oid GLOB '*[^0-9a-f]*'
                    OR length(NEW.final_commit_parent_oid) <> 64 OR NEW.final_commit_parent_oid GLOB '*[^0-9a-f]*'
                    OR NEW.final_commit_tree_oid <> NEW.candidate_tree_sha
                    OR NEW.final_commit_parent_oid <> NEW.candidate_base_sha
                ))
              OR (NEW.branch_publication_expected_oid IS NOT NULL AND (
                    length(NEW.branch_publication_expected_oid) <> 64
                    OR NEW.branch_publication_expected_oid GLOB '*[^0-9a-f]*'
                    OR NEW.final_commit_timestamp IS NULL
                ))
              OR (NEW.branch_publication_confirmed_at IS NOT NULL AND (
                    NEW.branch_publication_state <> 'confirmed'
                    OR NEW.confirmed_branch_publication_oid IS NULL
                    OR NEW.branch_publication_target_oid <> NEW.confirmed_branch_publication_oid
                ))
              OR (OLD.branch_publication_target_oid IS NOT NULL
                    AND NEW.branch_publication_target_oid IS NOT OLD.branch_publication_target_oid)
              OR (OLD.final_commit_kind IS NOT NULL AND (
                    NEW.final_commit_kind IS NOT OLD.final_commit_kind
                    OR NEW.final_commit_oid IS NOT OLD.final_commit_oid
                    OR NEW.final_commit_tree_oid IS NOT OLD.final_commit_tree_oid
                    OR NEW.final_commit_parent_oid IS NOT OLD.final_commit_parent_oid
                ))
              OR (OLD.branch_publication_expected_oid IS NOT NULL
                    AND NEW.branch_publication_expected_oid IS NOT OLD.branch_publication_expected_oid)
              OR (OLD.recorded_scope_sha256 IS NOT NULL AND NEW.recorded_scope_sha256 IS NOT NULL
                    AND NEW.recorded_scope_sha256 IS NOT OLD.recorded_scope_sha256)
              OR (OLD.recorded_scope_sha256 IS NOT NULL AND NEW.recorded_scope_sha256 IS NULL AND NOT (
                    OLD.pending_status_operation_id IS NOT NULL
                    AND NEW.pending_status_operation_id IS NULL
                    AND NEW.state = 'waiting' AND NEW.wait_reason = 'git_conflict'
                ))
              OR (NEW.recorded_scope_sha256 IS NOT NULL AND (
                    length(NEW.recorded_scope_sha256) <> 64 OR NEW.recorded_scope_sha256 GLOB '*[^0-9a-f]*'
                    OR NEW.confirmed_branch_publication_oid IS NULL
                ))
              OR (NEW.recorded_scope_sha256 IS NOT NULL AND NEW.pending_status_operation_id IS NULL)
            BEGIN SELECT RAISE(ABORT, 'invalid publish completion binding'); END
            SQL);
        $this->extendTicketMutationOperations(true);
        $this->extendInterventionGuard(true);
    }

    public function down(): void
    {
        $this->extendInterventionGuard(false);
        $this->extendTicketMutationOperations(false);
        DB::unprepared('DROP TRIGGER IF EXISTS runs_publish_completion_update_guard');
        $objects = $this->sqliteObjects('runs');
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn([
                'final_commit_oid', 'final_commit_kind', 'final_commit_tree_oid', 'final_commit_parent_oid',
                'branch_publication_target_oid', 'final_commit_timestamp',
                'branch_publication_expected_oid', 'branch_publication_state',
                'branch_publication_confirmed_at', 'recorded_scope_sha256',
            ]);
        });
        $this->restoreSqliteObjects($objects);
    }

    private function extendTicketMutationOperations(bool $include): void
    {
        $old = $include
            ? "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'"
            : "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only', 'complete_implementation'";
        $new = $include
            ? "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only', 'complete_implementation'"
            : "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'";
        $oldTargets = $include
            ? "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress'"
            : "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress', 'review'";
        $newTargets = $include
            ? "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress', 'review'"
            : "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress'";
        $reviewTargetClause = "OR (NEW.target_status = 'review' AND NOT EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND operation_type = 'ticket_status_change' AND json_extract(operation_parameters_jcs, '$.status_operation') = 'complete_implementation'))";
        $oldTargetGuard = "NEW.target_status NOT IN ($oldTargets)".($include ? '' : "\n                  $reviewTargetClause");
        $newTargetGuard = "NEW.target_status NOT IN ($newTargets)".($include ? "\n                  $reviewTargetClause" : '');
        foreach (['ticket_mutations_insert_guard', 'ticket_mutations_update_guard'] as $name) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            if (! is_object($row) || ! is_string($row->sql ?? null)) {
                throw new RuntimeException('The published ticket mutation guard is missing.');
            }
            $sql = str_replace($old, $new, $row->sql, $count);
            $sql = str_replace($oldTargetGuard, $newTargetGuard, $sql, $targetCount);
            if ($count !== 1 || $targetCount !== 1) {
                throw new RuntimeException('The publish ticket mutation guard could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($sql);
        }
    }

    private function extendInterventionGuard(bool $include): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'interventions_audit_insert_guard'");
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published intervention audit guard is missing.');
        }
        $old = $include
            ? "'manual_report','status_sync','manual_gate','security_gate'"
            : "'manual_report','status_sync','manual_gate','security_gate','manual_push'";
        $new = $include
            ? "'manual_report','status_sync','manual_gate','security_gate','manual_push'"
            : "'manual_report','status_sync','manual_gate','security_gate'";
        $sql = str_replace($old, $new, $row->sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The intervention audit guard could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER interventions_audit_insert_guard');
        DB::unprepared($sql);
    }

    /** @return list<array{name: string, sql: string}> */
    private function sqliteObjects(string $table): array
    {
        return array_values(array_filter(array_map(
            static fn (object $row): ?array => is_string($row->name ?? null) && is_string($row->sql ?? null)
                ? ['name' => $row->name, 'sql' => $row->sql] : null,
            DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger')", [$table]),
        )));
    }

    /** @param list<array{name: string, sql: string}> $objects */
    private function restoreSqliteObjects(array $objects): void
    {
        foreach ($objects as $object) {
            if (! is_object(DB::selectOne('SELECT 1 AS present FROM sqlite_master WHERE name = ?', [$object['name']]))) {
                DB::unprepared($object['sql']);
            }
        }
    }
};
