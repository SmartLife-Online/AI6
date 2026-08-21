<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('review_readiness_state')->nullable()->after('checkpoint_evidence_epoch');
            $table->json('review_blockers')->nullable()->after('review_readiness_state');
            $table->timestamp('review_readiness_assessed_at')->nullable()->after('review_blockers');
        });
        Schema::table('check_results', function (Blueprint $table): void {
            // Nullable only for the deterministic backfill below. The insert
            // guard rejects NULL, so every new producer must bind an epoch
            // explicitly instead of inheriting a misleading default.
            $table->unsignedBigInteger('evidence_epoch')->nullable()->after('run_id');
        });
        DB::unprepared('UPDATE check_results SET evidence_epoch = COALESCE((SELECT runs.evidence_epoch FROM runs WHERE runs.id = check_results.run_id), 0)');
        $this->setCheckResultEpochContract(true);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_readiness_guard BEFORE UPDATE ON runs
            WHEN (NEW.review_readiness_state IS NULL) <> (NEW.review_blockers IS NULL)
              OR (NEW.review_readiness_state IS NULL) <> (NEW.review_readiness_assessed_at IS NULL)
              OR (NEW.review_readiness_state IS NOT NULL AND NEW.review_readiness_state NOT IN ('ready', 'blocked'))
              OR (NEW.review_blockers IS NOT NULL AND (json_valid(NEW.review_blockers) <> 1 OR json_type(NEW.review_blockers) <> 'array'))
              OR (NEW.review_readiness_state = 'ready' AND json_array_length(NEW.review_blockers) <> 0)
              OR (NEW.review_readiness_state = 'blocked' AND json_array_length(NEW.review_blockers) = 0)
            BEGIN SELECT RAISE(ABORT, 'invalid review readiness'); END
            SQL);
        Schema::create('run_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            $table->char('predecessor_commit_sha', 64)->nullable();
            $table->char('commit_sha', 64);
            $table->char('tree_sha', 64);
            $table->char('diff_hash', 64);
            $table->unsignedBigInteger('evidence_epoch');
            $table->boolean('is_current')->default(true);
            $table->timestamps();
            $table->unique(['run_id', 'generation']);
            $table->unique(['run_id', 'commit_sha']);
        });
        DB::unprepared('CREATE UNIQUE INDEX run_checkpoints_one_current ON run_checkpoints (run_id) WHERE is_current = 1');

        DB::table('runs')->whereNotNull('checkpoint_commit_sha')->orderBy('id')->each(function (object $run): void {
            DB::table('run_checkpoints')->insert([
                'id' => (string) Str::uuid(), 'run_id' => $run->id, 'generation' => 1,
                'predecessor_commit_sha' => null, 'commit_sha' => $run->checkpoint_commit_sha,
                'tree_sha' => $run->checkpoint_tree_sha, 'diff_hash' => $run->checkpoint_diff_hash,
                'evidence_epoch' => $run->checkpoint_evidence_epoch ?? $run->evidence_epoch ?? 0,
                'is_current' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->allowProgression(true);
    }

    public function down(): void
    {
        $this->allowProgression(false);
        $this->setCheckResultEpochContract(false);
        DB::unprepared('DROP TRIGGER IF EXISTS runs_readiness_guard');
        DB::unprepared('DROP INDEX IF EXISTS run_checkpoints_one_current');
        Schema::dropIfExists('run_checkpoints');
        Schema::table('check_results', function (Blueprint $table): void {
            $table->dropColumn('evidence_epoch');
        });
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn(['review_readiness_state', 'review_blockers', 'review_readiness_assessed_at']);
        });
    }

    private function setCheckResultEpochContract(bool $enabled): void
    {
        $insert = $enabled
            ? "WHEN NEW.evidence_epoch IS NULL\n              OR NEW.phase"
            : 'WHEN NEW.phase';
        $this->rewrite(
            'check_results_insert_guard',
            '~WHEN\s+(?:NEW\.evidence_epoch\s+IS\s+NULL\s+OR\s+)?NEW\.phase~',
            $insert,
        );

        $update = $enabled
            ? "OR NEW.evidence_epoch IS NOT OLD.evidence_epoch\n              OR NEW.id"
            : 'OR NEW.id';
        $this->rewrite(
            'check_results_update_guard',
            '~OR\s+(?:NEW\.evidence_epoch\s+IS\s+NOT\s+OLD\.evidence_epoch\s+OR\s+)?NEW\.id~',
            $update,
        );

        DB::unprepared('DROP INDEX IF EXISTS check_results_live_execution_unique');
        DB::unprepared($enabled
            ? 'CREATE UNIQUE INDEX check_results_live_execution_unique ON check_results (run_id, phase, profile, tree_sha, evidence_epoch) WHERE superseded_at IS NULL'
            : 'CREATE UNIQUE INDEX check_results_live_execution_unique ON check_results (run_id, phase, profile, tree_sha) WHERE superseded_at IS NULL');
    }

    private function allowProgression(bool $allow): void
    {
        $checkpointPattern = '~OR\s+\(OLD\.checkpoint_commit_sha\s+IS\s+NOT\s+NULL\s+AND\s+\(NEW\.checkpoint_commit_sha\s+IS\s+NOT\s+OLD\.checkpoint_commit_sha\s+OR\s+NEW\.checkpoint_tree_sha\s+IS\s+NOT\s+OLD\.checkpoint_tree_sha\s+OR\s+NEW\.checkpoint_diff_hash\s+IS\s+NOT\s+OLD\.checkpoint_diff_hash\)\)(?:\s+AND\s+NOT\s+EXISTS\s+\(SELECT\s+1\s+FROM\s+run_checkpoints\s+cp\s+WHERE\s+cp\.run_id\s+=\s+NEW\.id\s+AND\s+cp\.is_current\s+=\s+1\s+AND\s+cp\.predecessor_commit_sha\s+=\s+OLD\.checkpoint_commit_sha\s+AND\s+cp\.commit_sha\s+=\s+NEW\.checkpoint_commit_sha\s+AND\s+cp\.tree_sha\s+=\s+NEW\.checkpoint_tree_sha\s+AND\s+cp\.diff_hash\s+=\s+NEW\.checkpoint_diff_hash\))?~';
        $checkpointBase = 'OR (OLD.checkpoint_commit_sha IS NOT NULL AND (NEW.checkpoint_commit_sha IS NOT OLD.checkpoint_commit_sha OR NEW.checkpoint_tree_sha IS NOT OLD.checkpoint_tree_sha OR NEW.checkpoint_diff_hash IS NOT OLD.checkpoint_diff_hash))';
        $checkpointExtended = $checkpointBase.' AND NOT EXISTS (SELECT 1 FROM run_checkpoints cp WHERE cp.run_id = NEW.id AND cp.is_current = 1 AND cp.predecessor_commit_sha = OLD.checkpoint_commit_sha AND cp.commit_sha = NEW.checkpoint_commit_sha AND cp.tree_sha = NEW.checkpoint_tree_sha AND cp.diff_hash = NEW.checkpoint_diff_hash)';
        $this->rewrite('runs_update_guard', $checkpointPattern, $allow ? $checkpointExtended : $checkpointBase);

        $epochPattern = '~OR\s+\(OLD\.checkpoint_evidence_epoch\s+IS\s+NOT\s+NULL\s+AND\s+NEW\.checkpoint_evidence_epoch\s+IS\s+NOT\s+OLD\.checkpoint_evidence_epoch\)(?:\s+AND\s+NOT\s+EXISTS\s+\(SELECT\s+1\s+FROM\s+run_checkpoints\s+cp\s+WHERE\s+cp\.run_id\s+=\s+NEW\.id\s+AND\s+cp\.is_current\s+=\s+1\s+AND\s+cp\.evidence_epoch\s+=\s+NEW\.checkpoint_evidence_epoch\s+AND\s+cp\.commit_sha\s+=\s+NEW\.checkpoint_commit_sha\))?~';
        $epochBase = 'OR (OLD.checkpoint_evidence_epoch IS NOT NULL AND NEW.checkpoint_evidence_epoch IS NOT OLD.checkpoint_evidence_epoch)';
        $epochExtended = $epochBase.' AND NOT EXISTS (SELECT 1 FROM run_checkpoints cp WHERE cp.run_id = NEW.id AND cp.is_current = 1 AND cp.evidence_epoch = NEW.checkpoint_evidence_epoch AND cp.commit_sha = NEW.checkpoint_commit_sha)';
        $this->rewrite('runs_amendment_update_guard', $epochPattern, $allow ? $epochExtended : $epochBase);
    }

    private function rewrite(string $name, string $pattern, string $replacement): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! property_exists($row, 'sql') || ! is_string($row->sql)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }
        $sql = preg_replace($pattern, $replacement, $row->sql, 1, $count);
        if (! is_string($sql) || $count !== 1) {
            throw new RuntimeException('The published SQLite guard '.$name.' could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
