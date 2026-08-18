<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('kind');
            $table->json('redacted_metadata');
            $table->char('digest', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('sequence');
            $table->string('storage_reference');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['run_id', 'kind']);
            $table->unique(['run_id', 'kind', 'digest']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_artifacts_insert_guard BEFORE INSERT ON run_artifacts
            WHEN NEW.kind NOT IN ('implementation_summary', 'provider_raw', 'limit_pending', 'limit_grant')
              OR length(NEW.digest) <> 64 OR NEW.digest GLOB '*[^0-9a-f]*'
              OR NEW.size_bytes < 0
              OR NEW.sequence < 1
              OR NEW.storage_reference = ''
              OR json_valid(NEW.redacted_metadata) <> 1
            BEGIN SELECT RAISE(ABORT, 'invalid run artifact insert'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS run_artifacts_insert_guard');
        Schema::dropIfExists('run_artifacts');
    }
};
