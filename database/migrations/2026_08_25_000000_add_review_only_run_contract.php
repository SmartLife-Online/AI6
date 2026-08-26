<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $approvalObjects = $this->sqliteObjects('ticket_approvals');
        Schema::table('ticket_approvals', function (Blueprint $table): void {
            $table->string('run_type')->default('implementation')->after('push_mode');
            $table->string('review_subject_reference', 2048)->nullable()->after('run_type');
            $table->string('completion_mode')->nullable()->after('review_subject_reference');
        });
        $this->restoreSqliteObjects($approvalObjects);

        $runObjects = $this->sqliteObjects('runs');
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('review_subject_reference', 2048)->nullable()->after('run_type');
            $table->string('completion_mode')->nullable()->after('review_subject_reference');
        });
        $this->restoreSqliteObjects($runObjects);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_approvals_insert_guard BEFORE INSERT ON ticket_approvals
            WHEN NEW.run_type NOT IN ('implementation', 'review_only')
              OR ((NEW.run_type = 'review_only') <> (NEW.review_subject_reference IS NOT NULL))
              OR ((NEW.run_type = 'review_only') <> (NEW.completion_mode IS NOT NULL))
              OR (NEW.review_subject_reference IS NOT NULL AND (trim(NEW.review_subject_reference) = '' OR length(NEW.review_subject_reference) > 2048))
              OR (NEW.completion_mode IS NOT NULL AND NEW.completion_mode NOT IN ('manual', 'automatic_after_gates'))
            BEGIN SELECT RAISE(ABORT, 'invalid review-only approval binding'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_approvals_update_guard BEFORE UPDATE ON ticket_approvals
            WHEN NEW.run_type <> OLD.run_type
              OR NEW.review_subject_reference IS NOT OLD.review_subject_reference
              OR NEW.completion_mode IS NOT OLD.completion_mode
            BEGIN SELECT RAISE(ABORT, 'review-only approval binding is immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_runs_insert_guard BEFORE INSERT ON runs
            WHEN NEW.run_type NOT IN ('implementation', 'review_only')
              OR ((NEW.run_type = 'review_only') <> (NEW.review_subject_reference IS NOT NULL))
              OR ((NEW.run_type = 'review_only') <> (NEW.completion_mode IS NOT NULL))
              OR (NEW.review_subject_reference IS NOT NULL AND (trim(NEW.review_subject_reference) = '' OR length(NEW.review_subject_reference) > 2048))
              OR (NEW.completion_mode IS NOT NULL AND NEW.completion_mode NOT IN ('manual', 'automatic_after_gates'))
              OR NOT EXISTS (
                    SELECT 1 FROM ticket_approvals approval
                    WHERE approval.id = NEW.ticket_approval_id
                      AND approval.run_type = NEW.run_type
                      AND approval.review_subject_reference IS NEW.review_subject_reference
                      AND approval.completion_mode IS NEW.completion_mode
                )
            BEGIN SELECT RAISE(ABORT, 'invalid review-only run binding'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER review_only_runs_update_guard BEFORE UPDATE ON runs
            WHEN NEW.run_type <> OLD.run_type
              OR NEW.review_subject_reference IS NOT OLD.review_subject_reference
              OR NEW.completion_mode IS NOT OLD.completion_mode
            BEGIN SELECT RAISE(ABORT, 'review-only run binding is immutable'); END
            SQL);

        $this->extendTicketMutationOperations(true);
        $this->extendInterventionWaitReasons(true);
    }

    public function down(): void
    {
        $this->extendInterventionWaitReasons(false);
        $this->extendTicketMutationOperations(false);
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_runs_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_runs_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_approvals_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS review_only_approvals_insert_guard');

        $runObjects = $this->sqliteObjects('runs');
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn(['review_subject_reference', 'completion_mode']);
        });
        $this->restoreSqliteObjects($runObjects);
        $approvalObjects = $this->sqliteObjects('ticket_approvals');
        Schema::table('ticket_approvals', function (Blueprint $table): void {
            $table->dropColumn(['run_type', 'review_subject_reference', 'completion_mode']);
        });
        $this->restoreSqliteObjects($approvalObjects);
    }

    private function extendTicketMutationOperations(bool $include): void
    {
        $old = $include
            ? "'block', 'cancel', 'return_to_todo', 'complete_review'"
            : "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'";
        $new = $include
            ? "'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'"
            : "'block', 'cancel', 'return_to_todo', 'complete_review'";
        foreach (['ticket_mutations_insert_guard', 'ticket_mutations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace($old, $new, $sql, $count);
            if ($count !== 1) {
                throw new RuntimeException('The ticket mutation status-operation guard could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($sql);
        }
    }

    private function extendInterventionWaitReasons(bool $include): void
    {
        $name = 'interventions_audit_insert_guard';
        $sql = $this->trigger($name);
        $old = $include
            ? "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict'"
            : "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict','manual_report','status_sync'";
        $new = $include
            ? "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict','manual_report','status_sync'"
            : "'provider_error','invalid_json','check_failure','git_base_changed','git_conflict'";
        $sql = str_replace($old, $new, $sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The intervention wait-reason guard could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }

    /** @return list<array{name: string, sql: string}> */
    private function sqliteObjects(string $table): array
    {
        return array_values(array_filter(array_map(
            static fn (object $row): ?array => is_string($row->name ?? null) && is_string($row->sql ?? null)
                ? ['name' => $row->name, 'sql' => $row->sql]
                : null,
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

    private function trigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }

        return $row->sql;
    }
};
