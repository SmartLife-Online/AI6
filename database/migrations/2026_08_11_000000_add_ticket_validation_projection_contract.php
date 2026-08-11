<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropGuards();
        Schema::table('ticket_read_models', function (Blueprint $table): void {
            $table->json('validation_errors')->default('[]')->after('ticket_contract_sha256');
            $table->boolean('editor_eligible')->default(false)->after('approval_editor_eligible');
            $table->boolean('approval_eligible')->default(false)->after('editor_eligible');
        });
        $this->createValidationGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::table('ticket_read_models', function (Blueprint $table): void {
            $table->dropColumn(['validation_errors', 'editor_eligible', 'approval_eligible']);
        });
        $this->createBootstrapGuards();
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_read_models_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS ticket_read_models_insert_guard');
    }

    private function createValidationGuards(): void
    {
        $base = $this->baseGuard();
        $state = <<<'SQL'
                OR NEW.approval_editor_eligible <> 0
                OR NEW.document_state NOT IN ('unparsed', 'invalid', 'valid')
                OR (NEW.document_state = 'unparsed' AND (
                    NEW.validation_profile IS NOT NULL
                    OR NEW.ticket_contract_sha256 IS NOT NULL
                    OR json(NEW.validation_errors) <> json('[]')
                    OR NEW.editor_eligible <> 0 OR NEW.approval_eligible <> 0
                    OR (NEW.redaction_state = 'clear' AND json(NEW.source_blockers) <> json('["unparsed"]'))
                    OR (NEW.redaction_state = 'content_redacted' AND json(NEW.source_blockers) <> json('["unparsed","content_redacted"]'))
                ))
                OR (NEW.document_state IN ('invalid', 'valid') AND (
                    NEW.validation_profile IS NULL
                    OR NEW.validation_profile NOT IN ('generic_v1', 'ai6_detail_v1')
                ))
                OR (NEW.document_state = 'valid' AND json(NEW.source_blockers) NOT IN (
                    json('[]'), json('["validation_profile_mismatch"]'),
                    json('["content_redacted"]'), json('["validation_profile_mismatch","content_redacted"]')
                ))
                OR (NEW.document_state = 'invalid' AND json(NEW.source_blockers) NOT IN (
                    json('["invalid"]'), json('["invalid","validation_profile_mismatch"]'),
                    json('["invalid","content_redacted"]'), json('["invalid","validation_profile_mismatch","content_redacted"]')
                ))
                OR (NEW.document_state = 'valid' AND (
                    NEW.ticket_contract_sha256 IS NULL
                    OR length(NEW.ticket_contract_sha256) <> 64
                    OR NEW.ticket_contract_sha256 GLOB '*[^0-9a-f]*'
                    OR json(NEW.validation_errors) <> json('[]')
                ))
                OR (NEW.document_state = 'invalid' AND (
                    NEW.ticket_contract_sha256 IS NOT NULL
                    OR json_array_length(NEW.validation_errors) = 0
                ))
                OR (NEW.document_state = 'valid' AND NEW.redaction_state = 'clear' AND json(NEW.source_blockers) = json('[]')
                    AND (NEW.editor_eligible <> 1 OR NEW.approval_eligible <> 1))
                OR (NEW.document_state = 'invalid' AND NEW.redaction_state = 'clear' AND json(NEW.source_blockers) = json('["invalid"]')
                    AND (NEW.editor_eligible <> 1 OR NEW.approval_eligible <> 0))
                OR ((NEW.redaction_state = 'content_redacted' OR json(NEW.source_blockers) LIKE '%validation_profile_mismatch%')
                    AND (NEW.editor_eligible <> 0 OR NEW.approval_eligible <> 0))
                OR (NEW.document_state = 'valid' AND NEW.approval_eligible = 1
                    AND NOT (NEW.redaction_state = 'clear' AND json(NEW.source_blockers) = json('[]')))
                OR (NEW.document_state = 'invalid' AND NEW.editor_eligible = 1
                    AND NOT (NEW.redaction_state = 'clear' AND json(NEW.source_blockers) = json('["invalid"]')))
                OR (NEW.document_state = 'invalid' AND NEW.approval_eligible <> 0)
                OR (NEW.document_state = 'valid' AND NEW.editor_eligible <> NEW.approval_eligible)
                OR EXISTS (
                    SELECT 1 FROM json_each(NEW.validation_errors)
                    WHERE json_type(value) <> 'object'
                       OR json_type(value, '$.code') <> 'text'
                       OR json_type(value, '$.field') <> 'text'
                       OR json_type(value, '$.message') <> 'text'
                )
        SQL;
        $insertOperation = $this->runningOperationBindingGuard();
        $updateOperation = $this->updateOperationBindingGuard();

        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_read_models_insert_guard
            BEFORE INSERT ON ticket_read_models
            WHEN $base
                $state
                OR $insertOperation
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket read model');
            END
            SQL);
        DB::unprepared(<<<SQL
            CREATE TRIGGER ticket_read_models_update_guard
            BEFORE UPDATE ON ticket_read_models
            WHEN NEW.id <> OLD.id OR NEW.project_id <> OLD.project_id OR NEW.relative_path <> OLD.relative_path
                OR $base
                $state
                OR $updateOperation
            BEGIN
                SELECT RAISE(ABORT, 'invalid ticket read model transition');
            END
            SQL);
    }

    private function baseGuard(): string
    {
        return <<<'SQL'
                NEW.relative_path = '' OR length(NEW.relative_path) > 1024
                OR substr(NEW.relative_path, 1, 1) = '/'
                OR instr(NEW.relative_path, '\') > 0
                OR instr('/' || NEW.relative_path || '/', '/../') > 0
                OR instr('/' || NEW.relative_path || '/', '/./') > 0
                OR instr(NEW.relative_path, '//') > 0
                OR length(NEW.control_commit) <> 64 OR NEW.control_commit GLOB '*[^0-9a-f]*'
                OR length(NEW.blob_sha) <> 64 OR NEW.blob_sha GLOB '*[^0-9a-f]*'
                OR NEW.redaction_state NOT IN ('clear', 'content_redacted')
                OR json_valid(NEW.redaction_matches) <> 1 OR json_type(NEW.redaction_matches) <> 'array'
                OR json_valid(NEW.validation_errors) <> 1 OR json_type(NEW.validation_errors) <> 'array'
                OR json_valid(NEW.source_blockers) <> 1 OR json_type(NEW.source_blockers) <> 'array'
                OR (NEW.redaction_state = 'clear' AND json_array_length(NEW.redaction_matches) <> 0)
                OR (NEW.redaction_state = 'content_redacted' AND json_array_length(NEW.redaction_matches) = 0)
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
        SQL;
    }

    private function runningOperationBindingGuard(): string
    {
        return <<<'SQL'
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
    }

    private function updateOperationBindingGuard(): string
    {
        return <<<'SQL'
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
    }

    private function createBootstrapGuards(): void
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
