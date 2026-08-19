<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_results', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('phase');
            $table->string('profile', 64);
            $table->string('state');
            $table->string('reason')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->text('redacted_output');
            $table->char('tree_sha', 64);
            $table->char('result_tree_sha', 64);
            $table->boolean('declared_side_effects');
            $table->boolean('declared_network');
            $table->boolean('declared_mutates');
            $table->char('result_key', 64);
            // An authorized retry on the unchanged tree supersedes its failed
            // predecessor instead of deleting it: the evidence stays readable,
            // and only the live row participates in the uniqueness below.
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'phase', 'profile']);
        });

        // Run, phase, profile and the checked tree are the four coordinates of
        // one execution (AC-10); the partial uniqueness rejects a redelivered
        // duplicate while leaving superseded predecessors in place.
        DB::unprepared('CREATE UNIQUE INDEX check_results_live_key_unique ON check_results (result_key) WHERE superseded_at IS NULL');
        DB::unprepared('CREATE UNIQUE INDEX check_results_live_execution_unique ON check_results (run_id, phase, profile, tree_sha) WHERE superseded_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER check_results_insert_guard BEFORE INSERT ON check_results
            WHEN NEW.phase NOT IN ('before_review', 'final')
              OR NEW.state NOT IN ('succeeded', 'failed', 'timed_out', 'tool_unavailable')
              OR NEW.profile = ''
              OR NEW.superseded_at IS NOT NULL
              OR (NEW.state = 'succeeded' AND NEW.reason IS NOT NULL)
              OR (NEW.state <> 'succeeded' AND (NEW.reason IS NULL OR NEW.reason = ''))
              OR (NEW.declared_mutates = 0 AND NEW.state = 'succeeded' AND NEW.result_tree_sha <> NEW.tree_sha)
              OR length(NEW.tree_sha) <> 64 OR NEW.tree_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.result_tree_sha) <> 64 OR NEW.result_tree_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.result_key) <> 64 OR NEW.result_key GLOB '*[^0-9a-f]*'
            BEGIN SELECT RAISE(ABORT, 'invalid check result'); END
            SQL);
        // A check result stays immutable. The single permitted transition is the
        // one-way supersede of a live, not successful result; every other column
        // and a second supersede are refused.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER check_results_update_guard BEFORE UPDATE ON check_results
            WHEN OLD.superseded_at IS NOT NULL
              OR NEW.superseded_at IS NULL
              OR OLD.state = 'succeeded'
              OR NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id OR NEW.phase <> OLD.phase
              OR NEW.profile <> OLD.profile OR NEW.state <> OLD.state
              OR NEW.exit_code IS NOT OLD.exit_code OR NEW.reason IS NOT OLD.reason
              OR NEW.duration_ms <> OLD.duration_ms OR NEW.redacted_output <> OLD.redacted_output
              OR NEW.tree_sha <> OLD.tree_sha OR NEW.result_tree_sha <> OLD.result_tree_sha
              OR NEW.declared_side_effects <> OLD.declared_side_effects
              OR NEW.declared_network <> OLD.declared_network
              OR NEW.declared_mutates <> OLD.declared_mutates
              OR NEW.result_key <> OLD.result_key
            BEGIN SELECT RAISE(ABORT, 'check results are immutable'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS check_results_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS check_results_insert_guard');
        DB::unprepared('DROP INDEX IF EXISTS check_results_live_execution_unique');
        DB::unprepared('DROP INDEX IF EXISTS check_results_live_key_unique');
        Schema::dropIfExists('check_results');
    }
};
