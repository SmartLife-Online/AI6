<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change'";

    private const NEW_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh'";

    private const CONFIG_REFRESH_GUARD = <<<'SQL'
    OR (NEW.operation_type = 'config_refresh' AND (
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
        $this->rewriteOperationGuards(true);

        Schema::create('project_config_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('control_operation_id')->unique()->constrained('control_operations')->cascadeOnDelete();
            $table->char('control_commit', 64);
            $table->char('blob_sha', 64)->nullable();
            $table->unsignedBigInteger('control_generation');
            $table->string('state');
            $table->char('config_hash', 64)->nullable();
            $table->json('normalized_config')->nullable();
            $table->json('validation_errors');
            $table->json('redaction_matches');
            $table->timestamps();

            $table->index(['project_id', 'control_generation', 'created_at']);
        });

        Schema::create('project_config_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('approval_id')->unique();
            $table->foreignId('draft_id')->constrained('project_config_drafts')->restrictOnDelete();
            $table->char('control_commit', 64);
            $table->char('blob_sha', 64);
            $table->char('config_hash', 64);
            $table->json('normalized_config');
            $table->unsignedBigInteger('control_generation');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['project_id', 'control_generation', 'approved_at']);
        });

        Schema::table('ticket_read_models', function (Blueprint $table): void {
            $table->char('effective_config_hash', 64)->nullable()->after('validation_profile');
        });

        $this->rewriteReadModelReprojectionGuard(true);
        $this->createConfigGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS project_config_snapshots_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS project_config_snapshots_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS project_config_drafts_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS project_config_drafts_insert_guard');
        $this->rewriteReadModelReprojectionGuard(false);
        Schema::table('ticket_read_models', fn (Blueprint $table) => $table->dropColumn('effective_config_hash'));
        Schema::dropIfExists('project_config_snapshots');
        Schema::dropIfExists('project_config_drafts');
        $this->rewriteOperationGuards(false);
    }

    private function rewriteOperationGuards(bool $include): void
    {
        $old = $include ? self::OLD_TYPES : self::NEW_TYPES;
        $new = $include ? self::NEW_TYPES : self::OLD_TYPES;
        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            $sql = is_object($row) && property_exists($row, 'sql') && is_string($row->sql) ? $row->sql : null;
            if ($sql === null) {
                throw new RuntimeException('The published control-operation guard is missing.');
            }
            $rewritten = str_replace("NEW.operation_type NOT IN ($old)", "NEW.operation_type NOT IN ($new)", $sql, $types);
            $anchor = 'OR ((NEW.finding_text IS NULL) <> (NEW.finding_hash IS NULL))';
            $rewritten = $include
                ? str_replace($anchor, self::CONFIG_REFRESH_GUARD."\n                ".$anchor, $rewritten, $guards)
                : str_replace(self::CONFIG_REFRESH_GUARD."\n                ", '', $rewritten, $guards);
            if ($types !== 1 || $guards !== 1) {
                throw new RuntimeException('The published control-operation guards could not be extended deterministically.');
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($rewritten);
        }
    }

    private function createConfigGuards(): void
    {
        $draft = <<<'SQL'
                NEW.state NOT IN ('absent', 'invalid', 'valid')
                OR length(NEW.control_commit) <> 64 OR NEW.control_commit GLOB '*[^0-9a-f]*'
                OR (NEW.blob_sha IS NOT NULL AND (length(NEW.blob_sha) <> 64 OR NEW.blob_sha GLOB '*[^0-9a-f]*'))
                OR (NEW.config_hash IS NOT NULL AND (length(NEW.config_hash) <> 64 OR NEW.config_hash GLOB '*[^0-9a-f]*'))
                OR json_valid(NEW.validation_errors) <> 1 OR json_type(NEW.validation_errors) <> 'array'
                OR json_valid(NEW.redaction_matches) <> 1 OR json_type(NEW.redaction_matches) <> 'array'
                OR (NEW.state = 'absent' AND (NEW.blob_sha IS NOT NULL OR NEW.config_hash IS NOT NULL OR NEW.normalized_config IS NOT NULL OR json_array_length(NEW.validation_errors) <> 0 OR json_array_length(NEW.redaction_matches) <> 0))
                OR (NEW.state = 'invalid' AND (NEW.blob_sha IS NULL OR NEW.config_hash IS NOT NULL OR NEW.normalized_config IS NOT NULL OR json_array_length(NEW.validation_errors) = 0))
                OR (NEW.state = 'valid' AND (NEW.blob_sha IS NULL OR NEW.config_hash IS NULL OR json_valid(NEW.normalized_config) <> 1 OR json_type(NEW.normalized_config) <> 'object' OR json_array_length(NEW.validation_errors) <> 0 OR json_array_length(NEW.redaction_matches) <> 0))
                OR NOT EXISTS (
                    SELECT 1 FROM control_operations
                    WHERE id = NEW.control_operation_id AND project_id = NEW.project_id
                      AND operation_type = 'config_refresh' AND expected_control_commit = NEW.control_commit
                      AND state = 'running' AND phase = 'claimed'
                )
        SQL;
        DB::unprepared("CREATE TRIGGER project_config_drafts_insert_guard BEFORE INSERT ON project_config_drafts WHEN $draft BEGIN SELECT RAISE(ABORT, 'invalid project config draft'); END");
        DB::unprepared("CREATE TRIGGER project_config_drafts_update_guard BEFORE UPDATE ON project_config_drafts BEGIN SELECT RAISE(ABORT, 'project config drafts are immutable'); END");

        $snapshot = <<<'SQL'
                length(NEW.control_commit) <> 64 OR NEW.control_commit GLOB '*[^0-9a-f]*'
                OR length(NEW.blob_sha) <> 64 OR NEW.blob_sha GLOB '*[^0-9a-f]*'
                OR length(NEW.config_hash) <> 64 OR NEW.config_hash GLOB '*[^0-9a-f]*'
                OR json_valid(NEW.normalized_config) <> 1 OR json_type(NEW.normalized_config) <> 'object'
                OR NOT EXISTS (
                    SELECT 1 FROM project_config_drafts
                    WHERE id = NEW.draft_id AND project_id = NEW.project_id AND state = 'valid'
                      AND control_commit = NEW.control_commit AND blob_sha = NEW.blob_sha
                      AND config_hash = NEW.config_hash AND control_generation = NEW.control_generation
                      AND json(normalized_config) = json(NEW.normalized_config)
                )
        SQL;
        DB::unprepared("CREATE TRIGGER project_config_snapshots_insert_guard BEFORE INSERT ON project_config_snapshots WHEN $snapshot BEGIN SELECT RAISE(ABORT, 'invalid project config snapshot'); END");
        DB::unprepared("CREATE TRIGGER project_config_snapshots_update_guard BEFORE UPDATE ON project_config_snapshots BEGIN SELECT RAISE(ABORT, 'project config snapshots are immutable'); END");
    }

    private function rewriteReadModelReprojectionGuard(bool $includeConfigChanges): void
    {
        $legacy = <<<'SQL'
        AND OLD.document_state = 'unparsed'
                                      AND OLD.validation_profile IS NULL
                                      AND NEW.control_operation_id = OLD.control_operation_id
        SQL;
        $extended = <<<'SQL'
        AND NEW.control_operation_id = OLD.control_operation_id
                                      AND (
                                          (OLD.document_state = 'unparsed' AND OLD.validation_profile IS NULL)
                                          OR (
                                              NEW.effective_config_hash IS NOT NULL
                                              AND (
                                                  OLD.effective_config_hash IS NOT NEW.effective_config_hash
                                                  OR OLD.validation_profile IS NOT NEW.validation_profile
                                              )
                                          )
                                      )
        SQL;
        $from = $includeConfigChanges ? $legacy : $extended;
        $to = $includeConfigChanges ? $extended : $legacy;
        $name = 'ticket_read_models_update_guard';
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        $sql = is_object($row) && property_exists($row, 'sql') && is_string($row->sql) ? $row->sql : null;
        if ($sql === null) {
            throw new RuntimeException('The published ticket read-model guard is missing.');
        }
        $rewritten = str_replace($from, $to, $sql, $replacements);
        if ($replacements !== 1) {
            throw new RuntimeException('The published ticket reprojection guard could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($rewritten);
    }
};
