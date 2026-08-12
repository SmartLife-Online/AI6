<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CURRENT_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh'";

    private const MUTATION_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change'";

    private const CURRENT_PHASES = "'queued', 'claimed', 'remote_probed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed', 'recovery_required'";

    private const MUTATION_PHASES = "'queued', 'claimed', 'remote_probed', 'launch_intent', 'process_started', 'key_generated', 'key_activated', 'provisioning_finalized', 'effect_staged', 'outcome_published', 'binding_finalized', 'attempt_completed', 'prepared', 'commit_prepared', 'control_confirmed', 'db_finalized', 'recovery_required'";

    private const MUTATION_GUARD = <<<'SQL'
    OR (NEW.operation_type IN ('ticket_edit', 'ticket_status_change') AND (
                    NEW.expected_control_commit IS NULL
                    OR NEW.effect_attempt_token IS NOT NULL
                    OR NEW.launch_argument_hash IS NOT NULL
                    OR NEW.process_id IS NOT NULL
                    OR NEW.process_started_at IS NOT NULL
                    OR NEW.phase NOT IN ('prepared', 'commit_prepared', 'control_confirmed', 'db_finalized', 'attempt_completed', 'recovery_required')
                    OR (NEW.state = 'queued' AND NEW.phase <> 'prepared')
                    OR (NEW.state = 'running' AND NEW.phase NOT IN ('prepared', 'commit_prepared', 'control_confirmed'))
                    OR (NEW.state = 'completed' AND NEW.phase <> 'db_finalized')
                    OR (NEW.state = 'failed' AND NEW.phase <> 'attempt_completed')
                    OR (NEW.state = 'abandoned' AND NEW.phase <> 'attempt_completed')
                    OR (NEW.state = 'recovery_required' AND NEW.phase <> 'recovery_required')
                    OR (NEW.target_control_oid IS NOT NULL AND NEW.phase = 'prepared')
                    OR (NEW.target_control_oid IS NULL AND NEW.phase IN ('commit_prepared', 'control_confirmed', 'db_finalized'))
                ))
    SQL;

    public function up(): void
    {
        $this->rewriteOperationGuards(true);
        $this->rewriteReadModelGuards(true);

        Schema::create('ticket_mutations', function (Blueprint $table): void {
            $table->foreignUuid('status_operation_id')->primary()->constrained('control_operations')->cascadeOnDelete();
            $table->string('relative_path', 1024);
            $table->char('expected_ticket_blob_sha', 64);
            $table->char('base_content_sha256', 64);
            $table->longText('base_content');
            $table->longText('target_content');
            $table->string('source_status');
            $table->string('target_status');
            $table->char('source_contract_sha256', 64)->nullable();
            $table->char('target_contract_sha256', 64);
            $table->char('expected_target_blob_sha', 64);
            $table->char('expected_target_tree_oid', 64);
            $table->char('prepared_commit_oid', 64)->nullable();
            $table->unsignedBigInteger('prepared_attempt_token')->nullable();
            $table->unsignedBigInteger('expected_control_binding_version');
            $table->text('audit_reason');
            $table->json('audit_redaction_matches');
            $table->boolean('external_completion_confirmed')->default(false);
            $table->unsignedBigInteger('commit_timestamp');
            $table->timestamps();

            $table->index(['relative_path', 'expected_ticket_blob_sha']);
        });

        $this->createTicketMutationGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_mutations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_mutations_insert_guard');
        Schema::dropIfExists('ticket_mutations');
        $this->rewriteReadModelGuards(false);
        $this->rewriteOperationGuards(false);
    }

    private function rewriteOperationGuards(bool $includeMutations): void
    {
        $oldTypes = $includeMutations ? self::CURRENT_TYPES : self::MUTATION_TYPES;
        $newTypes = $includeMutations ? self::MUTATION_TYPES : self::CURRENT_TYPES;
        $oldPhases = $includeMutations ? self::CURRENT_PHASES : self::MUTATION_PHASES;
        $newPhases = $includeMutations ? self::MUTATION_PHASES : self::CURRENT_PHASES;

        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            $sql = is_object($row) && property_exists($row, 'sql') && is_string($row->sql) ? $row->sql : null;
            if ($sql === null) {
                throw new RuntimeException('The published control-operation guard is missing.');
            }

            $rewritten = str_replace(
                'NEW.operation_type NOT IN ('.$oldTypes.')',
                'NEW.operation_type NOT IN ('.$newTypes.')',
                $sql,
                $typeReplacements,
            );
            $rewritten = str_replace(
                'NEW.phase NOT IN ('.$oldPhases.')',
                'NEW.phase NOT IN ('.$newPhases.')',
                $rewritten,
                $phaseReplacements,
            );
            if ($typeReplacements !== 1 || $phaseReplacements !== 1) {
                throw new RuntimeException('The published control-operation enum guards could not be extended deterministically.');
            }

            $anchor = 'OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))';
            if ($includeMutations) {
                $rewritten = str_replace($anchor, self::MUTATION_GUARD."\n                ".$anchor, $rewritten, $guardReplacements);
            } else {
                $rewritten = str_replace(self::MUTATION_GUARD."\n                ", '', $rewritten, $guardReplacements);
            }
            if ($guardReplacements !== 1) {
                throw new RuntimeException('The published control-operation mutation guard could not be extended deterministically.');
            }

            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($rewritten);
        }
    }

    private function createTicketMutationGuards(): void
    {
        $guard = <<<'SQL'
                NEW.relative_path = '' OR length(NEW.relative_path) > 1024
                OR substr(NEW.relative_path, 1, 1) = '/'
                OR instr(NEW.relative_path, '\') > 0
                OR instr('/' || NEW.relative_path || '/', '/../') > 0
                OR instr('/' || NEW.relative_path || '/', '/./') > 0
                OR instr(NEW.relative_path, '//') > 0
                OR length(NEW.expected_ticket_blob_sha) <> 64 OR NEW.expected_ticket_blob_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.base_content_sha256) <> 64 OR NEW.base_content_sha256 GLOB '*[^0-9a-f]*'
                OR length(NEW.target_contract_sha256) <> 64 OR NEW.target_contract_sha256 GLOB '*[^0-9a-f]*'
                OR length(NEW.expected_target_blob_sha) <> 64 OR NEW.expected_target_blob_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.expected_target_tree_oid) <> 64 OR NEW.expected_target_tree_oid GLOB '*[^0-9a-f]*'
                OR (NEW.source_contract_sha256 IS NOT NULL AND (length(NEW.source_contract_sha256) <> 64 OR NEW.source_contract_sha256 GLOB '*[^0-9a-f]*'))
                OR (NEW.prepared_commit_oid IS NOT NULL AND (length(NEW.prepared_commit_oid) <> 64 OR NEW.prepared_commit_oid GLOB '*[^0-9a-f]*'))
                OR ((NEW.prepared_commit_oid IS NULL) <> (NEW.prepared_attempt_token IS NULL))
                OR (NEW.prepared_attempt_token IS NOT NULL AND NEW.prepared_attempt_token < 1)
                OR NEW.source_status NOT IN ('todo', 'ready', 'blocked', 'review')
                OR NEW.target_status NOT IN ('todo', 'blocked', 'cancelled', 'done')
                OR NEW.audit_reason = ''
                OR json_valid(NEW.audit_redaction_matches) <> 1 OR json_type(NEW.audit_redaction_matches) <> 'array'
                OR NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.status_operation_id
                      AND operation_type IN ('ticket_edit', 'ticket_status_change')
                      AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                      AND (
                          (operation_type = 'ticket_edit' AND json_type(operation_parameters_jcs, '$.status_operation') IS NULL)
                          OR (
                              operation_type = 'ticket_status_change'
                              AND json_extract(operation_parameters_jcs, '$.status_operation') IN ('block', 'cancel', 'return_to_todo', 'complete_review')
                          )
                      )
                )
        SQL;

        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_mutations_insert_guard
            BEFORE INSERT ON ticket_mutations
            WHEN $guard
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket mutation');
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_mutations_update_guard
            BEFORE UPDATE ON ticket_mutations
            WHEN NEW.status_operation_id <> OLD.status_operation_id
                OR NEW.relative_path <> OLD.relative_path
                OR NEW.expected_ticket_blob_sha <> OLD.expected_ticket_blob_sha
                OR NEW.base_content_sha256 <> OLD.base_content_sha256
                OR NEW.base_content <> OLD.base_content
                OR NEW.target_content <> OLD.target_content
                OR NEW.source_status <> OLD.source_status
                OR NEW.target_status <> OLD.target_status
                OR NEW.source_contract_sha256 IS NOT OLD.source_contract_sha256
                OR NEW.target_contract_sha256 <> OLD.target_contract_sha256
                OR NEW.expected_target_blob_sha <> OLD.expected_target_blob_sha
                OR (NEW.expected_target_tree_oid <> OLD.expected_target_tree_oid AND NOT (
                    OLD.expected_target_tree_oid = '0000000000000000000000000000000000000000000000000000000000000000'
                    AND NEW.expected_target_tree_oid <> '0000000000000000000000000000000000000000000000000000000000000000'
                    AND EXISTS (
                        SELECT 1 FROM control_operations
                        WHERE id = NEW.status_operation_id AND phase = 'prepared' AND state = 'running'
                    )
                ))
                OR NEW.expected_control_binding_version <> OLD.expected_control_binding_version
                OR NEW.audit_reason <> OLD.audit_reason
                OR json(NEW.audit_redaction_matches) <> json(OLD.audit_redaction_matches)
                OR NEW.external_completion_confirmed <> OLD.external_completion_confirmed
                OR NEW.commit_timestamp <> OLD.commit_timestamp
                OR ((NEW.prepared_commit_oid IS NOT OLD.prepared_commit_oid OR NEW.prepared_attempt_token IS NOT OLD.prepared_attempt_token) AND NOT (
                    OLD.prepared_commit_oid IS NULL
                    AND OLD.prepared_attempt_token IS NULL
                    AND NEW.prepared_commit_oid IS NOT NULL
                    AND NEW.prepared_attempt_token IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM control_operations
                        WHERE id = NEW.status_operation_id
                          AND state = 'running'
                          AND phase = 'prepared'
                          AND target_control_oid IS NULL
                    )
                ))
                OR $guard
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket mutation transition');
            END
            SQL);
    }

    private function rewriteReadModelGuards(bool $includeMutations): void
    {
        $insertRefresh = <<<'SQL'
NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id
                      AND project_id = NEW.project_id
                      AND operation_type = 'ticket_refresh'
                      AND expected_control_commit = NEW.control_commit
                      AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                      AND state = 'running'
                      AND phase = 'claimed'
                )
SQL;
        $updateRefresh = <<<'SQL'
NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id
                      AND project_id = NEW.project_id
                      AND operation_type = 'ticket_refresh'
                      AND expected_control_commit = NEW.control_commit
                      AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                      AND (
                          (state = 'running' AND phase = 'claimed')
                          OR (
                              state = 'completed' AND phase = 'attempt_completed'
                              AND OLD.document_state = 'unparsed'
                              AND OLD.validation_profile IS NULL
                              AND NEW.control_operation_id = OLD.control_operation_id
                              AND NEW.control_commit = OLD.control_commit
                              AND NEW.blob_sha = OLD.blob_sha
                              AND NEW.control_generation = OLD.control_generation
                          )
                      )
                )
SQL;
        $mutation = <<<'SQL'
 OR EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id
                      AND project_id = NEW.project_id
                      AND operation_type IN ('ticket_edit', 'ticket_status_change')
                      AND target_control_oid = NEW.control_commit
                      AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                      AND state = 'running'
                      AND phase = 'control_confirmed'
                )
SQL;

        foreach (['ticket_read_models_insert_guard' => $insertRefresh, 'ticket_read_models_update_guard' => $updateRefresh] as $name => $refresh) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            $sql = is_object($row) && property_exists($row, 'sql') && is_string($row->sql) ? $row->sql : null;
            if ($sql === null) {
                throw new RuntimeException('The published ticket-read-model guard is missing.');
            }
            $current = $includeMutations ? $refresh : 'NOT (EXISTS ('.substr($refresh, strlen('NOT EXISTS ('), -1).')'.$mutation.')';
            $replacement = $includeMutations ? 'NOT (EXISTS ('.substr($refresh, strlen('NOT EXISTS ('), -1).')'.$mutation.')' : $refresh;
            $pattern = '/'.preg_replace('/\\s+/', '\\\\s+', preg_quote($current, '/')).'/';
            if (! is_string($pattern)) {
                throw new RuntimeException('The ticket-read-model guard pattern could not be constructed.');
            }
            $rewritten = preg_replace_callback(
                $pattern,
                static fn (): string => $replacement,
                $sql,
                1,
                $replacements,
            );
            if (! is_string($rewritten)) {
                throw new RuntimeException('The ticket-read-model operation guard rewrite failed.');
            }
            if ($replacements !== 1) {
                throw new RuntimeException('The published ticket-read-model operation guard could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($rewritten);
        }
    }
};
