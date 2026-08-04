<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_confirmations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('code_digest', 64);
            $table->string('recipient_digest', 64);
            $table->string('session_digest', 64);
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('delivery_status', 16);
            $table->timestamp('delivery_status_changed_at');
            $table->string('failure_key', 64)->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'session_digest', 'revision']);
            $table->index(['user_id', 'consumed_at', 'invalidated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_confirmations');
    }
};
