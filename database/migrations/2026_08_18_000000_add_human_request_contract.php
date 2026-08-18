<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('kind');
            $table->string('response_mode');
            $table->text('title');
            $table->text('message');
            $table->text('why_needed');
            $table->json('options');
            $table->string('recommended_option')->nullable();
            $table->json('affected_paths');
            $table->json('criterion_refs');
            $table->json('allowed_effects');
            $table->string('required_action');
            $table->unsignedInteger('bound_run_version');
            $table->string('bound_ticket_contract');
            $table->string('bound_checkpoint');
            $table->string('bound_scope');
            $table->string('bound_agent_slot');
            $table->string('bound_requested_effect');
            $table->string('bound_step_key');
            $table->string('delivery_status');
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->unsignedInteger('delivery_revision');
            $table->string('delivery_failure_key')->nullable();
            $table->timestamp('delivery_status_changed_at')->nullable();
            $table->string('resolution_state');
            $table->foreignId('attention_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->timestamp('resolved_at')->nullable();
            $table->index(['project_id', 'resolution_state']);
            $table->index(['run_id', 'resolution_state']);
        });

        DB::statement("CREATE UNIQUE INDEX human_requests_one_open_blocking_per_run ON human_requests (run_id) WHERE resolution_state = 'open'");

        Schema::create('interventions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('human_request_id')->unique()->constrained('human_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('chosen_effect');
            $table->string('chosen_option_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interventions');
        Schema::dropIfExists('human_requests');
    }
};
