<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->json('effective_scope_snapshot')->nullable()->after('scope_hash');
            $table->char('effective_scope_hash', 64)->nullable()->after('effective_scope_snapshot');
            $table->unsignedInteger('added_scope_paths_count')->default(0)->after('effective_scope_hash');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_scope_insert_guard BEFORE INSERT ON runs
            WHEN (NEW.effective_scope_hash IS NOT NULL AND (length(NEW.effective_scope_hash) <> 64 OR NEW.effective_scope_hash GLOB '*[^0-9a-f]*'))
              OR NEW.added_scope_paths_count <> 0
            BEGIN SELECT RAISE(ABORT, 'invalid run scope state'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_scope_update_guard BEFORE UPDATE ON runs
            WHEN (NEW.effective_scope_hash IS NOT NULL AND (length(NEW.effective_scope_hash) <> 64 OR NEW.effective_scope_hash GLOB '*[^0-9a-f]*'))
              OR NEW.added_scope_paths_count < OLD.added_scope_paths_count
            BEGIN SELECT RAISE(ABORT, 'invalid run scope transition'); END
            SQL);

        Schema::create('scope_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('path', 1024);
            $table->string('outcome');
            $table->foreignUuid('human_request_id')->nullable()->constrained('human_requests')->nullOnDelete();
            $table->timestamps();
            $table->unique(['run_id', 'path']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER scope_decisions_insert_guard BEFORE INSERT ON scope_decisions
            WHEN NEW.path = '' OR length(NEW.path) > 1024 OR NEW.outcome NOT IN ('approved', 'rejected')
            BEGIN SELECT RAISE(ABORT, 'invalid scope decision'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER scope_decisions_update_guard BEFORE UPDATE ON scope_decisions
            BEGIN SELECT RAISE(ABORT, 'scope decisions are immutable'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_decisions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS scope_decisions_insert_guard');
        Schema::dropIfExists('scope_decisions');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_scope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_scope_insert_guard');
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn(['effective_scope_snapshot', 'effective_scope_hash', 'added_scope_paths_count']);
        });
    }
};
