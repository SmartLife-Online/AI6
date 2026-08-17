<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('step_type');
            $table->unsignedInteger('step_number');
            $table->string('idempotency_key')->unique();
            $table->string('state')->default('planned');
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code')->nullable();
            $table->text('intent')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'step_type', 'step_number']);
            $table->index(['run_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_jobs');
    }
};
