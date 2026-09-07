<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_artifacts', function (Blueprint $table): void {
            $table->char('execution_id', 64)->nullable();
            $table->dropUnique('run_artifacts_run_id_kind_digest_unique');
        });
        DB::unprepared('CREATE UNIQUE INDEX run_artifacts_legacy_digest_unique ON run_artifacts (run_id, kind, digest) WHERE execution_id IS NULL');
        DB::unprepared('CREATE UNIQUE INDEX run_artifacts_execution_unique ON run_artifacts (run_id, kind, execution_id) WHERE execution_id IS NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_artifacts_execution_insert_guard BEFORE INSERT ON run_artifacts
            WHEN NEW.execution_id IS NOT NULL AND (NEW.kind <> 'provider_raw'
              OR length(NEW.execution_id) <> 64 OR NEW.execution_id GLOB '*[^0-9a-f]*')
            BEGIN SELECT RAISE(ABORT, 'invalid artifact execution binding'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_artifacts_execution_update_guard BEFORE UPDATE ON run_artifacts
            WHEN NEW.execution_id IS NOT OLD.execution_id
            BEGIN SELECT RAISE(ABORT, 'immutable artifact execution binding'); END
            SQL);
    }

    public function down(): void
    {
        // Never discard distinct turn metadata to force the old uniqueness.
        if (DB::table('run_artifacts')->whereNotNull('digest')
            ->groupBy('run_id', 'kind', 'digest')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('The legacy artifact uniqueness cannot represent the stored provider turns.');
        }
        DB::unprepared('DROP TRIGGER run_artifacts_execution_insert_guard');
        DB::unprepared('DROP TRIGGER run_artifacts_execution_update_guard');
        DB::unprepared('DROP INDEX run_artifacts_legacy_digest_unique');
        DB::unprepared('DROP INDEX run_artifacts_execution_unique');
        DB::unprepared('ALTER TABLE run_artifacts DROP COLUMN execution_id');
        DB::unprepared('CREATE UNIQUE INDEX run_artifacts_run_id_kind_digest_unique ON run_artifacts (run_id, kind, digest)');
    }
};
