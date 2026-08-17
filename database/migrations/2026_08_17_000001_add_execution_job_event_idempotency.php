<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_events', function (Blueprint $table): void {
            $table->string('event_key')->nullable()->unique()->after('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('run_events', function (Blueprint $table): void {
            $table->dropUnique(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};
