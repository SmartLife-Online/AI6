<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The named decision reason of every path taken into the effective scope
 * (plan §8.2, TKT-007/TKT-012).
 *
 * The published guards of 2026_08_18_020000 stay untouched; this follow-up adds
 * one column and one additional insert guard that closes its value set
 * server-side. Neither project nor provider content can widen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scope_decisions', function (Blueprint $table): void {
            $table->string('reason', 32)->default('auto_allow')->after('outcome');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER scope_decisions_reason_insert_guard BEFORE INSERT ON scope_decisions
            WHEN NEW.reason NOT IN ('auto_allow', 'unlisted_auto_allow', 'human_approved', 'human_rejected', 'amendment')
            BEGIN SELECT RAISE(ABORT, 'invalid scope decision reason'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS scope_decisions_reason_insert_guard');
        Schema::table('scope_decisions', function (Blueprint $table): void {
            $table->dropColumn('reason');
        });
    }
};
