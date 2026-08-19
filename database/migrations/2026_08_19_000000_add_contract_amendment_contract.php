<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RUN_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh', 'ticket_approval', 'run_start'";

    private const AMENDMENT_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh', 'ticket_approval', 'run_start', 'contract_amendment'";

    private const RUN_MUTATIONS = "('ticket_edit', 'ticket_status_change', 'ticket_approval', 'run_start')";

    private const AMENDMENT_MUTATIONS = "('ticket_edit', 'ticket_status_change', 'ticket_approval', 'run_start', 'contract_amendment')";

    private const RUN_IN_PROGRESS_SOURCES = "operation_type = 'ticket_status_change'))";

    private const AMENDMENT_IN_PROGRESS_SOURCES = "operation_type IN ('ticket_status_change', 'contract_amendment')))";

    private const RUN_OPERATION_CLAUSE = "OR (operation_type = 'run_start' AND json_extract(operation_parameters_jcs, '\$.relative_path') = NEW.relative_path)";

    private const AMENDMENT_OPERATION_CLAUSE = "OR (operation_type = 'run_start' AND json_extract(operation_parameters_jcs, '\$.relative_path') = NEW.relative_path)
                      OR (operation_type = 'contract_amendment' AND json_extract(operation_parameters_jcs, '\$.run_id') IS NOT NULL)";

    private const RUN_ARTIFACT_KINDS = "'implementation_summary', 'provider_raw', 'limit_pending', 'limit_grant'";

    private const AMENDMENT_ARTIFACT_KINDS = "'implementation_summary', 'provider_raw', 'limit_pending', 'limit_grant', 'quarantined_path'";

    public function up(): void
    {
        $this->rewriteAmendmentGuards(true);

        Schema::table('runs', function (Blueprint $table): void {
            $table->char('ticket_blob_sha', 64)->nullable()->after('added_scope_paths_count');
            $table->char('ticket_contract_sha256', 64)->nullable()->after('ticket_blob_sha');
            $table->json('actual_changed_paths_snapshot')->nullable()->after('ticket_contract_sha256');
            $table->char('actual_changed_paths_hash', 64)->nullable()->after('actual_changed_paths_snapshot');
            $table->unsignedBigInteger('evidence_epoch')->default(0)->after('actual_changed_paths_hash');
            $table->unsignedBigInteger('checkpoint_evidence_epoch')->nullable()->after('evidence_epoch');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_amendment_insert_guard BEFORE INSERT ON runs
            WHEN NEW.ticket_blob_sha IS NOT NULL OR NEW.ticket_contract_sha256 IS NOT NULL
              OR NEW.actual_changed_paths_snapshot IS NOT NULL OR NEW.actual_changed_paths_hash IS NOT NULL
              OR NEW.evidence_epoch <> 0 OR NEW.checkpoint_evidence_epoch IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'invalid run amendment state'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_amendment_update_guard BEFORE UPDATE ON runs
            WHEN (NEW.ticket_blob_sha IS NOT NULL AND (length(NEW.ticket_blob_sha) <> 64 OR NEW.ticket_blob_sha GLOB '*[^0-9a-f]*'))
              OR (NEW.ticket_contract_sha256 IS NOT NULL AND (length(NEW.ticket_contract_sha256) <> 64 OR NEW.ticket_contract_sha256 GLOB '*[^0-9a-f]*'))
              OR (NEW.actual_changed_paths_hash IS NOT NULL AND (length(NEW.actual_changed_paths_hash) <> 64 OR NEW.actual_changed_paths_hash GLOB '*[^0-9a-f]*'))
              OR ((NEW.actual_changed_paths_snapshot IS NULL) <> (NEW.actual_changed_paths_hash IS NULL))
              OR NEW.evidence_epoch < OLD.evidence_epoch
              OR (OLD.checkpoint_evidence_epoch IS NOT NULL AND NEW.checkpoint_evidence_epoch IS NOT OLD.checkpoint_evidence_epoch)
              OR (NEW.checkpoint_evidence_epoch IS NOT NULL AND NEW.checkpoint_commit_sha IS NULL)
            BEGIN SELECT RAISE(ABORT, 'invalid run amendment transition'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS runs_amendment_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_amendment_insert_guard');
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn([
                'ticket_blob_sha',
                'ticket_contract_sha256',
                'actual_changed_paths_snapshot',
                'actual_changed_paths_hash',
                'evidence_epoch',
                'checkpoint_evidence_epoch',
            ]);
        });
        $this->rewriteAmendmentGuards(false);
    }

    private function rewriteAmendmentGuards(bool $include): void
    {
        $oldTypes = $include ? self::RUN_TYPES : self::AMENDMENT_TYPES;
        $newTypes = $include ? self::AMENDMENT_TYPES : self::RUN_TYPES;
        $oldMutations = $include ? self::RUN_MUTATIONS : self::AMENDMENT_MUTATIONS;
        $newMutations = $include ? self::AMENDMENT_MUTATIONS : self::RUN_MUTATIONS;

        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace("NEW.operation_type NOT IN ($oldTypes)", "NEW.operation_type NOT IN ($newTypes)", $sql, $types);
            $sql = str_replace('NEW.operation_type IN '.$oldMutations, 'NEW.operation_type IN '.$newMutations, $sql, $mutations);
            if ($types !== 1 || $mutations !== 1) {
                throw new RuntimeException('The control-operation amendment guards could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }

        foreach (['ticket_read_models_insert_guard', 'ticket_read_models_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace('operation_type IN '.$oldMutations, 'operation_type IN '.$newMutations, $sql, $count);
            if ($count !== 1) {
                throw new RuntimeException('The ticket-read-model amendment guard could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }

        $oldKinds = $include ? self::RUN_ARTIFACT_KINDS : self::AMENDMENT_ARTIFACT_KINDS;
        $newKinds = $include ? self::AMENDMENT_ARTIFACT_KINDS : self::RUN_ARTIFACT_KINDS;
        foreach (['run_artifacts_insert_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace("NEW.kind NOT IN ($oldKinds)", "NEW.kind NOT IN ($newKinds)", $sql, $kinds);
            if ($kinds !== 1) {
                throw new RuntimeException('The run-artifact quarantine guard could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }

        $oldSources = $include ? self::RUN_IN_PROGRESS_SOURCES : self::AMENDMENT_IN_PROGRESS_SOURCES;
        $newSources = $include ? self::AMENDMENT_IN_PROGRESS_SOURCES : self::RUN_IN_PROGRESS_SOURCES;
        $oldClause = $include ? self::RUN_OPERATION_CLAUSE : self::AMENDMENT_OPERATION_CLAUSE;
        $newClause = $include ? self::AMENDMENT_OPERATION_CLAUSE : self::RUN_OPERATION_CLAUSE;

        foreach (['ticket_mutations_insert_guard', 'ticket_mutations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace('operation_type IN '.$oldMutations, 'operation_type IN '.$newMutations, $sql, $types);
            $sql = str_replace($oldSources, $newSources, $sql, $sources);
            $sql = str_replace($oldClause, $newClause, $sql, $operation);
            if ($types !== 1 || $sources !== 1 || $operation !== 1) {
                throw new RuntimeException('The ticket-mutation amendment guards could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }
    }

    private function trigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! property_exists($row, 'sql') || ! is_string($row->sql)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }

        return $row->sql;
    }

    private function replaceTrigger(string $name, string $sql): void
    {
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
