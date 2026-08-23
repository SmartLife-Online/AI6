<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignUuid('review_result_id')->constrained('review_results')->restrictOnDelete();
            $table->unsignedInteger('round_number');
            $table->uuid('slot_id');
            $table->string('provider_profile');
            $table->string('model');
            $table->string('effort');
            $table->string('prompt_profile');
            $table->char('checkpoint_tree_sha', 64);
            $table->char('diff_hash', 64);
            $table->string('local_id');
            $table->string('severity');
            $table->string('original_disposition');
            $table->string('category');
            $table->text('file');
            $table->unsignedInteger('line');
            $table->text('title');
            $table->text('evidence');
            $table->text('expected_result');
            $table->json('criterion_refs');
            $table->char('duplicate_group', 64);
            $table->timestamps();
            $table->unique(['review_result_id', 'local_id']);
            $table->index(['run_id', 'round_number', 'slot_id']);
            $table->index(['run_id', 'duplicate_group']);
        });

        Schema::create('criterion_coverages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignUuid('review_result_id')->constrained('review_results')->restrictOnDelete();
            $table->unsignedInteger('round_number');
            $table->uuid('slot_id');
            $table->string('criterion_id');
            $table->string('status');
            $table->text('evidence');
            $table->timestamps();
            $table->unique(['review_result_id', 'criterion_id']);
            $table->index(['run_id', 'round_number', 'slot_id']);
        });

        Schema::create('instruction_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignUuid('review_result_id')->constrained('review_results')->restrictOnDelete();
            $table->unsignedInteger('round_number');
            $table->uuid('slot_id');
            $table->text('title');
            $table->text('recommendation');
            $table->text('reason');
            $table->timestamps();
            $table->index(['run_id', 'round_number', 'slot_id']);
        });

        Schema::create('finding_dispositions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('finding_id')->constrained('findings')->restrictOnDelete();
            $table->string('type');
            $table->text('reason');
            $table->string('decision_source');
            $table->foreignUuid('evidence_review_result_id')->nullable()->constrained('review_results')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision_role')->nullable();
            $table->char('step_up_proof_hash', 64)->nullable();
            $table->unsignedBigInteger('expected_run_version');
            $table->char('request_hash', 64)->unique();
            $table->char('ticket_contract_sha256', 64);
            $table->char('config_hash', 64);
            $table->char('scope_hash', 64);
            $table->char('prompt_hash', 64);
            $table->char('instruction_hash', 64);
            $table->char('runtime_profile_hash', 64);
            $table->char('agent_profile_hash', 64);
            $table->char('security_policy_hash', 64);
            $table->char('checkpoint_tree_sha', 64);
            $table->char('diff_hash', 64);
            $table->char('reviewer_binding_hash', 64);
            $table->timestamps();
            $table->index(['finding_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER findings_insert_guard BEFORE INSERT ON findings
            WHEN NEW.round_number < 1 OR NEW.slot_id = '' OR NEW.local_id = ''
              OR NEW.provider_profile = '' OR NEW.model = '' OR NEW.effort = '' OR NEW.prompt_profile = ''
              OR NEW.severity NOT IN ('critical','high','medium','low','must_fix','human_required','suggestion','follow_up')
              OR NEW.original_disposition NOT IN ('open','must_fix','human_required','suggestion','follow_up')
              OR NEW.category NOT IN ('contract','correctness','security','tests','architecture','database','concurrency','performance','scope','documentation','other')
              OR NEW.line < 1 OR NEW.title = '' OR NEW.evidence = '' OR NEW.expected_result = ''
              OR NEW.checkpoint_tree_sha NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')
              OR NEW.diff_hash NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')
              OR NEW.duplicate_group NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')
              OR NOT EXISTS (
                  SELECT 1 FROM review_results source
                  WHERE source.id = NEW.review_result_id AND source.run_id = NEW.run_id
                    AND source.round_number = NEW.round_number AND source.slot_id = NEW.slot_id
                    AND source.provider_profile = NEW.provider_profile AND source.model = NEW.model
                    AND source.effort = NEW.effort AND source.prompt_profile = NEW.prompt_profile
                    AND source.checkpoint_tree_sha = NEW.checkpoint_tree_sha AND source.diff_hash = NEW.diff_hash
                    AND source.invocation_outcome = 'valid_result'
              )
            BEGIN SELECT RAISE(ABORT, 'invalid finding contract'); END
            SQL);
        $this->immutable('findings', 'findings');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER criterion_coverages_insert_guard BEFORE INSERT ON criterion_coverages
            WHEN NEW.criterion_id = '' OR NEW.status = '' OR NEW.evidence = '' OR NOT EXISTS (
                SELECT 1 FROM review_results source
                WHERE source.id = NEW.review_result_id AND source.run_id = NEW.run_id
                  AND source.round_number = NEW.round_number AND source.slot_id = NEW.slot_id
                  AND source.invocation_outcome = 'valid_result'
            )
            BEGIN SELECT RAISE(ABORT, 'invalid criterion coverage contract'); END
            SQL);
        $this->immutable('criterion_coverages', 'criterion coverages');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER instruction_recommendations_insert_guard BEFORE INSERT ON instruction_recommendations
            WHEN NEW.title = '' OR NEW.recommendation = '' OR NEW.reason = '' OR NOT EXISTS (
                SELECT 1 FROM review_results source
                WHERE source.id = NEW.review_result_id AND source.run_id = NEW.run_id
                  AND source.round_number = NEW.round_number AND source.slot_id = NEW.slot_id
                  AND source.invocation_outcome = 'valid_result'
            )
            BEGIN SELECT RAISE(ABORT, 'invalid instruction recommendation contract'); END
            SQL);
        $this->immutable('instruction_recommendations', 'instruction recommendations');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER finding_dispositions_insert_guard BEFORE INSERT ON finding_dispositions
            WHEN NEW.type NOT IN ('fixed','not_applicable','accepted_risk')
              OR NEW.decision_source NOT IN ('server_review','human_override') OR NEW.reason = ''
              OR (NEW.type = 'fixed' AND (NEW.decision_source <> 'server_review' OR NEW.evidence_review_result_id IS NULL OR NEW.decided_by IS NOT NULL OR NEW.decision_role IS NOT NULL OR NEW.step_up_proof_hash IS NOT NULL))
              OR (NEW.type = 'fixed' AND NOT EXISTS (
                  SELECT 1 FROM review_results evidence
                  JOIN findings original ON original.id = NEW.finding_id
                  WHERE evidence.id = NEW.evidence_review_result_id
                    AND evidence.id <> original.review_result_id
                    AND evidence.run_id = original.run_id
                    AND evidence.invocation_outcome = 'valid_result'
                    AND evidence.result_status = 'nothing_to_fix'
                    AND evidence.checkpoint_tree_sha = NEW.checkpoint_tree_sha
                    AND evidence.diff_hash = NEW.diff_hash
                    AND evidence.checkpoint_tree_sha <> original.checkpoint_tree_sha
              ))
              OR (NEW.type <> 'fixed' AND (NEW.decision_source <> 'human_override' OR NEW.evidence_review_result_id IS NOT NULL OR NEW.decided_by IS NULL OR NEW.decision_role IS NULL OR NEW.decision_role = '' OR NEW.step_up_proof_hash IS NULL))
            BEGIN SELECT RAISE(ABORT, 'invalid finding disposition contract'); END
            SQL);
        $this->immutable('finding_dispositions', 'finding dispositions');
    }

    public function down(): void
    {
        foreach (['finding_dispositions', 'instruction_recommendations', 'criterion_coverages', 'findings'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_delete_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_update_guard");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS instruction_recommendations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS criterion_coverages_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS finding_dispositions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS findings_insert_guard');
        Schema::dropIfExists('finding_dispositions');
        Schema::dropIfExists('instruction_recommendations');
        Schema::dropIfExists('criterion_coverages');
        Schema::dropIfExists('findings');
    }

    private function immutable(string $table, string $label): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, '{$label} are immutable'); END");
        DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, '{$label} are immutable'); END");
    }
};
