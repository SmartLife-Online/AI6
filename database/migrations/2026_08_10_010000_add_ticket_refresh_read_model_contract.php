<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REFRESH_GUARD = <<<'SQL'
    OR (NEW.operation_type = 'ticket_refresh' AND (
                    NEW.expected_control_commit IS NULL
                    OR NEW.effect_attempt_token IS NOT NULL
                    OR NEW.target_control_oid IS NOT NULL
                    OR NEW.launch_argument_hash IS NOT NULL
                    OR NEW.process_id IS NOT NULL
                    OR NEW.process_started_at IS NOT NULL
                    OR NEW.phase NOT IN ('queued', 'claimed', 'attempt_completed')
                    OR NEW.state = 'recovery_required'
                ))
    SQL;

    public function up(): void
    {
        $this->rewriteOperationGuards(includeRefresh: true);

        Schema::create('ticket_read_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('control_operation_id')->constrained('control_operations')->cascadeOnDelete();
            $table->string('relative_path', 1024);
            $table->char('control_commit', 64);
            $table->char('blob_sha', 64);
            $table->unsignedBigInteger('control_generation');
            $table->string('validation_profile')->nullable();
            $table->string('document_state');
            $table->char('ticket_contract_sha256', 64)->nullable();
            $table->text('redacted_content');
            $table->string('redaction_state');
            $table->json('redaction_matches');
            $table->json('source_blockers');
            $table->boolean('approval_editor_eligible')->default(false);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['project_id', 'relative_path']);
            $table->index(['project_id', 'generated_at']);
            $table->index('control_operation_id');
        });

        $this->createReadModelGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_read_models_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_read_models_insert_guard');
        Schema::dropIfExists('ticket_read_models');
        $this->rewriteOperationGuards(includeRefresh: false);
    }

    private function rewriteOperationGuards(bool $includeRefresh): void
    {
        $currentTypes = $includeRefresh
            ? "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change'"
            : "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh'";
        $replacementTypes = $includeRefresh
            ? "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh'"
            : "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change'";

        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $row = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?",
                [$name],
            );
            $sql = is_object($row) && property_exists($row, 'sql') && is_string($row->sql)
                ? $row->sql
                : null;
            if ($sql === null) {
                throw new RuntimeException('The published control-operation guard is missing.');
            }

            $rewritten = str_replace(
                "NEW.operation_type NOT IN ($currentTypes)",
                "NEW.operation_type NOT IN ($replacementTypes)",
                $sql,
                $typeReplacements,
            );
            if ($typeReplacements !== 1) {
                throw new RuntimeException('The published control-operation type guard could not be extended deterministically.');
            }

            if ($includeRefresh) {
                $findingAnchor = 'OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))';
                $rewritten = str_replace(
                    $findingAnchor,
                    self::REFRESH_GUARD."\n                ".$findingAnchor,
                    $rewritten,
                    $guardReplacements,
                );
            } else {
                $rewritten = str_replace(self::REFRESH_GUARD."\n                ", '', $rewritten, $guardReplacements);
            }
            if ($guardReplacements !== 1) {
                throw new RuntimeException('The published control-operation phase guard could not be extended deterministically.');
            }

            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($rewritten);
        }
    }

    private function createReadModelGuards(): void
    {
        $guard = <<<'SQL'

                NEW.relative_path = '' OR length(NEW.relative_path) > 1024
                OR substr(NEW.relative_path, 1, 1) = '/'
                OR instr(NEW.relative_path, '\') > 0
                OR instr('/' || NEW.relative_path || '/', '/../') > 0
                OR instr('/' || NEW.relative_path || '/', '/./') > 0
                OR instr(NEW.relative_path, '//') > 0
                OR length(NEW.control_commit) <> 64 OR NEW.control_commit GLOB '*[^0-9a-f]*'
                OR length(NEW.blob_sha) <> 64 OR NEW.blob_sha GLOB '*[^0-9a-f]*'
                OR NEW.validation_profile IS NOT NULL
                OR NEW.document_state <> 'unparsed'
                OR NEW.ticket_contract_sha256 IS NOT NULL
                OR NEW.redaction_state NOT IN ('clear', 'content_redacted')
                OR json_valid(NEW.redaction_matches) <> 1
                OR json_type(NEW.redaction_matches) <> 'array'
                OR json_valid(NEW.source_blockers) <> 1
                OR json_type(NEW.source_blockers) <> 'array'
                OR NEW.approval_editor_eligible <> 0
                OR (NEW.redaction_state = 'clear' AND json_array_length(NEW.redaction_matches) <> 0)
                OR (NEW.redaction_state = 'clear' AND json(NEW.source_blockers) <> json('["unparsed"]'))
                OR (NEW.redaction_state = 'content_redacted' AND json_array_length(NEW.redaction_matches) = 0)
                OR (NEW.redaction_state = 'content_redacted' AND json(NEW.source_blockers) <> json('["unparsed","content_redacted"]'))
                OR EXISTS (
                    SELECT 1 FROM json_each(NEW.redaction_matches)
                    WHERE json_type(value) <> 'object'
                       OR json_type(value, '$.type') <> 'text'
                       OR json_type(value, '$.field') <> 'text'
                       OR json_type(value, '$.start') <> 'integer'
                       OR json_type(value, '$.length') <> 'integer'
                       OR json_type(value, '$.marker') <> 'text'
                       OR json_type(value, '$.fingerprint_version') <> 'integer'
                       OR json_type(value, '$.key_id') <> 'text'
                       OR json_type(value, '$.fingerprint') <> 'text'
                       OR length(json_extract(value, '$.fingerprint')) <> 64
                       OR json_extract(value, '$.fingerprint') GLOB '*[^0-9a-f]*'
                )
                OR NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id
                      AND project_id = NEW.project_id
                      AND operation_type = 'ticket_refresh'
                      AND state = 'running'
                      AND phase = 'claimed'
                      AND expected_control_commit = NEW.control_commit
                      AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path
                )
        SQL;

        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_read_models_insert_guard
            BEFORE INSERT ON ticket_read_models
            WHEN $guard
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket read model');
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_read_models_update_guard
            BEFORE UPDATE ON ticket_read_models
            WHEN
                NEW.id <> OLD.id
                OR NEW.project_id <> OLD.project_id
                OR NEW.relative_path <> OLD.relative_path
                OR $guard
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket read model transition');
            END
            SQL);
    }
};
