<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->constrained('findings')->restrictOnDelete();
            $table->foreignUuid('review_result_id')->nullable()->constrained('review_results')->restrictOnDelete();
            $table->string('source_role');
            $table->unsignedInteger('round_number');
            $table->uuid('slot_id');
            $table->string('status');
            $table->text('evidence');
            $table->char('checkpoint_tree_sha', 64);
            $table->string('source_provider_profile');
            $table->string('source_model');
            $table->string('source_effort');
            $table->string('source_prompt_profile');
            $table->timestamps();
            $table->unique(['finding_id', 'slot_id', 'round_number']);
            $table->index(['run_id', 'round_number', 'slot_id']);
        });

        // A reviewer entry binds the valid review result it came from and always
        // judges a finding of an earlier round. An implementation entry is pure
        // evidence from the fix turn for the round it is fixing; it carries no
        // review result and never resolves a blockade.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER finding_statuses_insert_guard BEFORE INSERT ON finding_statuses
            WHEN NEW.round_number < 1 OR NEW.status NOT IN ('fixed','partially_fixed','not_fixed','not_applicable')
              OR NEW.source_role NOT IN ('quality_review','implementation')
              OR NEW.evidence = '' OR NEW.source_provider_profile = '' OR NEW.source_model = ''
              OR NEW.source_effort = '' OR NEW.source_prompt_profile = ''
              OR NEW.checkpoint_tree_sha NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')
              OR (NEW.source_role = 'quality_review' AND NEW.round_number < 2)
              OR (NEW.source_role = 'implementation' AND NEW.review_result_id IS NOT NULL)
              OR NOT EXISTS (
                  SELECT 1 FROM findings original
                  WHERE original.id = NEW.finding_id AND original.run_id = NEW.run_id
                    AND (
                      (NEW.source_role = 'quality_review' AND original.round_number < NEW.round_number)
                      OR (NEW.source_role = 'implementation' AND original.round_number <= NEW.round_number)
                    )
              )
              OR (NEW.source_role = 'quality_review' AND NOT EXISTS (
                  SELECT 1 FROM review_results source
                  WHERE source.id = NEW.review_result_id AND source.run_id = NEW.run_id
                    AND source.round_number = NEW.round_number AND source.slot_id = NEW.slot_id
                    AND source.checkpoint_tree_sha = NEW.checkpoint_tree_sha
                    AND source.provider_profile = NEW.source_provider_profile
                    AND source.model = NEW.source_model AND source.effort = NEW.source_effort
                    AND source.prompt_profile = NEW.source_prompt_profile
                    AND source.invocation_outcome = 'valid_result'
              ))
              OR (NEW.source_role = 'implementation' AND NOT EXISTS (
                  SELECT 1 FROM run_agents agent
                  WHERE agent.run_id = NEW.run_id AND agent.slot_id = NEW.slot_id
                    AND agent.role = 'implementation'
                    AND agent.provider_profile = NEW.source_provider_profile
                    AND agent.model = NEW.source_model AND agent.effort = NEW.source_effort
                    AND agent.prompt_profile = NEW.source_prompt_profile
              ))
            BEGIN SELECT RAISE(ABORT, 'invalid finding status contract'); END
            SQL);
        DB::unprepared("CREATE TRIGGER finding_statuses_update_guard BEFORE UPDATE ON finding_statuses BEGIN SELECT RAISE(ABORT, 'finding statuses are immutable'); END");
        DB::unprepared("CREATE TRIGGER finding_statuses_delete_guard BEFORE DELETE ON finding_statuses BEGIN SELECT RAISE(ABORT, 'finding statuses are immutable'); END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS finding_statuses_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS finding_statuses_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS finding_statuses_insert_guard');
        Schema::dropIfExists('finding_statuses');
    }
};
