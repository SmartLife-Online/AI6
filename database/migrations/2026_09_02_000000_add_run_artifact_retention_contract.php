<?php

use App\AI6\Runs\RetentionCategory;
use App\AI6\Runs\RetentionPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI6-031: retention state, deletion time and the central HMAC fingerprint on
 * the existing run-artifact record, plus the retention tombstone transition of
 * a check log (SEC-011, plan §10.6).
 *
 * The raw reference of an artifact — its storage reference and its unkeyed
 * content digest — becomes removable after deletion, so a tombstone keeps only
 * redacted metadata, the project- and run-bound fingerprint with key id and
 * version, size, expiry and deletion time. No second artifact, tombstone or
 * audit table is created.
 */
return new class extends Migration
{
    private const CHECK_LOG_TOMBSTONE = '[AI6-RETENTION-TOMBSTONE:check_logs]';

    private const INSERT_DIGEST_OLD = "OR length(NEW.digest) <> 64 OR NEW.digest GLOB '*[^0-9a-f]*'";

    private const INSERT_DIGEST_NEW = "OR NEW.digest IS NULL OR length(NEW.digest) <> 64 OR NEW.digest GLOB '*[^0-9a-f]*'";

    private const INSERT_REFERENCE_OLD = "OR NEW.storage_reference = ''";

    private const INSERT_REFERENCE_NEW = "OR NEW.storage_reference IS NULL OR NEW.storage_reference = ''";

    private const INSERT_METADATA_OLD = 'OR json_valid(NEW.redacted_metadata) <> 1';

    private const INSERT_METADATA_NEW = "OR json_valid(NEW.redacted_metadata) <> 1\n              OR NEW.retention_state <> 'stored' OR NEW.deleted_at IS NOT NULL\n              OR NEW.fingerprint IS NULL OR length(NEW.fingerprint) <> 64 OR NEW.fingerprint GLOB '*[^0-9a-f]*'\n              OR NEW.fingerprint_key_id IS NULL OR NEW.fingerprint_key_id = ''\n              OR NEW.fingerprint_version IS NULL OR NEW.fingerprint_version < 1";

    private const CHECK_GUARD_HEAD_OLD = 'WHEN OLD.superseded_at IS NOT NULL';

    private const CHECK_GUARD_HEAD_NEW = "WHEN NOT (NEW.redacted_output = '[AI6-RETENTION-TOMBSTONE:check_logs]'\n                AND OLD.retention_deleted_at IS NULL AND NEW.retention_deleted_at IS NOT NULL\n                AND (NEW.retention_expires_at IS OLD.retention_expires_at OR (OLD.retention_expires_at IS NULL AND NEW.retention_expires_at IS NOT NULL))\n                AND (NEW.retention_size_bytes IS OLD.retention_size_bytes OR (OLD.retention_size_bytes IS NULL AND NEW.retention_size_bytes >= 0))\n                AND ((OLD.fingerprint IS NOT NULL AND NEW.fingerprint IS OLD.fingerprint AND NEW.fingerprint_key_id IS OLD.fingerprint_key_id AND NEW.fingerprint_version IS OLD.fingerprint_version)\n                  OR (OLD.fingerprint IS NULL AND NEW.fingerprint IS NOT NULL AND length(NEW.fingerprint) = 64 AND NOT (NEW.fingerprint GLOB '*[^0-9a-f]*')\n                    AND NEW.fingerprint_key_id IS NOT NULL AND NEW.fingerprint_key_id <> '' AND NEW.fingerprint_version IS NOT NULL AND NEW.fingerprint_version >= 1))\n                AND NEW.superseded_at IS OLD.superseded_at AND NEW.id = OLD.id AND NEW.run_id = OLD.run_id\n                AND NEW.evidence_epoch IS OLD.evidence_epoch AND NEW.phase = OLD.phase AND NEW.profile = OLD.profile\n                AND NEW.state = OLD.state AND NEW.reason IS OLD.reason AND NEW.exit_code IS OLD.exit_code\n                AND NEW.duration_ms = OLD.duration_ms AND NEW.tree_sha = OLD.tree_sha AND NEW.result_tree_sha = OLD.result_tree_sha\n                AND NEW.declared_side_effects = OLD.declared_side_effects AND NEW.declared_network = OLD.declared_network\n                AND NEW.declared_mutates = OLD.declared_mutates AND NEW.result_key = OLD.result_key\n                AND NEW.created_at IS OLD.created_at)\n              AND (OLD.superseded_at IS NOT NULL";

    private const CHECK_GUARD_TAIL_OLD = "OR NEW.result_key <> OLD.result_key\nBEGIN";

    private const CHECK_GUARD_TAIL_NEW = "OR NEW.result_key <> OLD.result_key\n              OR NEW.retention_deleted_at IS NOT OLD.retention_deleted_at OR NEW.retention_expires_at IS NOT OLD.retention_expires_at\n              OR NEW.retention_size_bytes IS NOT OLD.retention_size_bytes OR NEW.fingerprint IS NOT OLD.fingerprint\n              OR NEW.fingerprint_key_id IS NOT OLD.fingerprint_key_id OR NEW.fingerprint_version IS NOT OLD.fingerprint_version)\nBEGIN";

    private const CHECK_INSERT_OLD = 'OR NEW.superseded_at IS NOT NULL';

    private const CHECK_INSERT_NEW = "OR NEW.superseded_at IS NOT NULL\n              OR NEW.retention_deleted_at IS NOT NULL OR NEW.retention_expires_at IS NULL OR NEW.retention_size_bytes IS NULL OR NEW.retention_size_bytes < 0\n              OR NEW.fingerprint IS NULL OR length(NEW.fingerprint) <> 64 OR NEW.fingerprint GLOB '*[^0-9a-f]*'\n              OR NEW.fingerprint_key_id IS NULL OR NEW.fingerprint_key_id = ''\n              OR NEW.fingerprint_version IS NULL OR NEW.fingerprint_version < 1";

    private const RUN_LOG_TOMBSTONE = '[AI6-RETENTION-TOMBSTONE:run_logs]';

    private const ARTIFACT_KINDS_OLD = 'NEW.kind NOT IN (';

    private const ARTIFACT_KINDS_NEW = "NEW.kind <> 'checkpoint_diff' AND NEW.kind NOT IN (";

    public function up(): void
    {
        $insertGuard = $this->trigger('run_artifacts_insert_guard');

        // SQLite rebuilds the table for a nullability change. The published
        // indexes and foreign keys are carried over by the rebuild, but every
        // trigger that names the table — its own guard and the guards of other
        // tables that look it up — is captured first and recreated verbatim
        // afterwards; the rename would otherwise fail on a dangling reference.
        $referencingGuards = $this->triggersReferencing('run_artifacts');
        foreach (array_keys($referencingGuards) as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        Schema::table('run_artifacts', function (Blueprint $table): void {
            $table->char('digest', 64)->nullable()->change();
            $table->string('storage_reference')->nullable()->change();
        });
        foreach ($referencingGuards as $name => $sql) {
            if ($name !== 'run_artifacts_insert_guard') {
                DB::unprepared($sql);
            }
        }
        Schema::table('run_artifacts', function (Blueprint $table): void {
            $table->string('retention_state')->default('stored')->after('expires_at');
            $table->timestamp('deleted_at')->nullable()->after('retention_state');
            $table->unsignedInteger('fingerprint_version')->nullable()->after('deleted_at');
            $table->string('fingerprint_key_id')->nullable()->after('fingerprint_version');
            $table->char('fingerprint', 64)->nullable()->after('fingerprint_key_id');
            $table->index(['retention_state', 'expires_at'], 'run_artifacts_retention');
        });

        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_DIGEST_OLD, self::INSERT_DIGEST_NEW);
        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_REFERENCE_OLD, self::INSERT_REFERENCE_NEW);
        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_METADATA_OLD, self::INSERT_METADATA_NEW);
        // The redacted textual diff of a bound checkpoint is one more artifact
        // kind; it is admitted in front of the published list so the published
        // migrations keep matching that list verbatim on their own round trip.
        $insertGuard = $this->replaceOnce($insertGuard, self::ARTIFACT_KINDS_OLD, self::ARTIFACT_KINDS_NEW);
        DB::unprepared($insertGuard);

        // The only permitted mutation of a stored artifact is its retention
        // deletion: raw reference and unkeyed digest disappear, the deletion
        // time and the tombstone state appear, everything else stays. A
        // fingerprint may be recorded once for a legacy row and never changes
        // afterwards; a deleted artifact never becomes stored again.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_artifacts_update_guard BEFORE UPDATE ON run_artifacts
            WHEN NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id OR NEW.kind <> OLD.kind
              OR json(NEW.redacted_metadata) <> json(OLD.redacted_metadata)
              OR NEW.size_bytes <> OLD.size_bytes OR NEW.sequence <> OLD.sequence
              OR NEW.expires_at <> OLD.expires_at OR NEW.created_at IS NOT OLD.created_at
              OR NEW.retention_state NOT IN ('stored', 'deleted')
              OR (OLD.fingerprint IS NOT NULL AND (NEW.fingerprint IS NOT OLD.fingerprint
                OR NEW.fingerprint_key_id IS NOT OLD.fingerprint_key_id
                OR NEW.fingerprint_version IS NOT OLD.fingerprint_version))
              OR (NEW.fingerprint IS NOT NULL AND (length(NEW.fingerprint) <> 64 OR NEW.fingerprint GLOB '*[^0-9a-f]*'
                OR NEW.fingerprint_key_id IS NULL OR NEW.fingerprint_key_id = ''
                OR NEW.fingerprint_version IS NULL OR NEW.fingerprint_version < 1))
              OR (NEW.fingerprint IS NULL AND (NEW.fingerprint_key_id IS NOT NULL OR NEW.fingerprint_version IS NOT NULL))
              OR (OLD.retention_state = 'deleted' AND (NEW.retention_state <> 'deleted'
                OR NEW.deleted_at IS NOT OLD.deleted_at OR NEW.digest IS NOT NULL OR NEW.storage_reference IS NOT NULL))
              OR (OLD.retention_state = 'stored' AND NEW.retention_state = 'stored'
                AND (NEW.digest IS NOT OLD.digest OR NEW.storage_reference IS NOT OLD.storage_reference OR NEW.deleted_at IS NOT NULL))
              OR (OLD.retention_state = 'stored' AND NEW.retention_state = 'deleted'
                AND (NEW.deleted_at IS NULL OR NEW.digest IS NOT NULL OR NEW.storage_reference IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'invalid run artifact transition'); END
            SQL);

        // A log tombstone is bound the same way as an artifact tombstone: the
        // central project- and run-bound HMAC fingerprint of the removed
        // redacted text with key id and version, its size, the expiry that
        // applied and the deletion time — on the existing rows, never in a
        // second table. Adding the columns rebuilds nothing, so the guards of
        // both tables survive and are extended below.
        foreach (['run_events', 'check_results'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('retention_expires_at')->nullable();
                $blueprint->timestamp('retention_deleted_at')->nullable();
                $blueprint->unsignedBigInteger('retention_size_bytes')->nullable();
                $blueprint->unsignedInteger('fingerprint_version')->nullable();
                $blueprint->string('fingerprint_key_id')->nullable();
                $blueprint->char('fingerprint', 64)->nullable();
            });
        }

        // Every new event and check result carries its retention binding —
        // expiry, size and HMAC fingerprint with key id and version — from
        // its insert on. A check result stays immutable except for exactly
        // one further transition: the retention run replaces its redacted
        // output with the fixed tombstone marker and sets the deletion time
        // while the binding stays as it is (a migrated legacy row receives
        // its fingerprint in the same statement) and every other column keeps its value. The transition
        // is recognized by the bound deletion time, never by the previous
        // output text: an output that regularly printed the marker is
        // deleted like any other. A new result never carries tombstone
        // columns.
        $checkInsertGuard = $this->trigger('check_results_insert_guard');
        $checkInsertGuard = $this->replaceOnce($checkInsertGuard, self::CHECK_INSERT_OLD, self::CHECK_INSERT_NEW);
        DB::unprepared('DROP TRIGGER check_results_insert_guard');
        DB::unprepared($checkInsertGuard);
        $checkGuard = $this->trigger('check_results_update_guard');
        $checkGuard = $this->replaceOnce($checkGuard, self::CHECK_GUARD_HEAD_OLD, self::CHECK_GUARD_HEAD_NEW);
        $checkGuard = $this->replaceOnce($checkGuard, self::CHECK_GUARD_TAIL_OLD, self::CHECK_GUARD_TAIL_NEW);
        DB::unprepared('DROP TRIGGER check_results_update_guard');
        // The rows stored before this contract are bound exactly once, here,
        // under the trusted retention configuration of this upgrade: the
        // expiry their category grants from their creation and the size of
        // their redacted text. Neither the page nor the retention run ever
        // derives an expiry from the configuration current at read time, so
        // a later raise of the days neither shows expired legacy text again
        // nor postpones its deletion. The fingerprint is not bound here: the
        // migration runs in the keyless init role, and the retention run
        // completes it with the tombstone. The guards are recreated only
        // after the backfill; while the published update guard is absent,
        // superseded results receive their binding like live ones.
        $this->bindLegacyRows('run_events', 'redacted_payload', RetentionCategory::RUN_LOGS);
        $this->bindLegacyRows('check_results', 'redacted_output', RetentionCategory::CHECK_LOGS);
        DB::unprepared($checkGuard);

        // A timeline event gets the same one-way transition: the payload
        // becomes the run-log tombstone and the bound tombstone columns
        // appear together and never change again. The event key stays,
        // because the idempotency of redelivered messages and the bound
        // security-skip evidence depend on it; the one change the transition
        // admits is the rebind of a legacy unkeyed 64-hex digest to a bound
        // `hmac:` identity, so no unkeyed message hash survives the deletion.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_events_retention_insert_guard BEFORE INSERT ON run_events
            WHEN NEW.retention_deleted_at IS NOT NULL OR NEW.retention_expires_at IS NULL OR NEW.retention_size_bytes IS NULL OR NEW.retention_size_bytes < 0
              OR NEW.fingerprint IS NULL OR length(NEW.fingerprint) <> 64 OR NEW.fingerprint GLOB '*[^0-9a-f]*'
              OR NEW.fingerprint_key_id IS NULL OR NEW.fingerprint_key_id = ''
              OR NEW.fingerprint_version IS NULL OR NEW.fingerprint_version < 1
            BEGIN SELECT RAISE(ABORT, 'invalid run event'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_events_retention_update_guard BEFORE UPDATE ON run_events
            WHEN NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id OR NEW.event_type <> OLD.event_type OR NEW.created_at IS NOT OLD.created_at
              OR (OLD.retention_deleted_at IS NOT NULL AND (NEW.retention_deleted_at IS NOT OLD.retention_deleted_at
                OR NEW.redacted_payload <> OLD.redacted_payload OR NEW.event_key IS NOT OLD.event_key
                OR NEW.retention_expires_at IS NOT OLD.retention_expires_at OR NEW.retention_size_bytes IS NOT OLD.retention_size_bytes
                OR NEW.fingerprint IS NOT OLD.fingerprint OR NEW.fingerprint_key_id IS NOT OLD.fingerprint_key_id
                OR NEW.fingerprint_version IS NOT OLD.fingerprint_version))
              OR (OLD.retention_deleted_at IS NULL AND NEW.retention_deleted_at IS NOT NULL AND (NEW.redacted_payload <> '[AI6-RETENTION-TOMBSTONE:run_logs]'
                OR NOT (NEW.event_key IS OLD.event_key OR (OLD.event_key IS NOT NULL AND length(OLD.event_key) = 64
                  AND NOT (OLD.event_key GLOB '*[^0-9a-f]*') AND NEW.event_key GLOB 'hmac:*'))
                OR NOT (NEW.retention_expires_at IS OLD.retention_expires_at OR (OLD.retention_expires_at IS NULL AND NEW.retention_expires_at IS NOT NULL))
                OR NOT (NEW.retention_size_bytes IS OLD.retention_size_bytes OR (OLD.retention_size_bytes IS NULL AND NEW.retention_size_bytes >= 0))
                OR NOT ((OLD.fingerprint IS NOT NULL AND NEW.fingerprint IS OLD.fingerprint AND NEW.fingerprint_key_id IS OLD.fingerprint_key_id AND NEW.fingerprint_version IS OLD.fingerprint_version)
                  OR (OLD.fingerprint IS NULL AND NEW.fingerprint IS NOT NULL AND length(NEW.fingerprint) = 64 AND NOT (NEW.fingerprint GLOB '*[^0-9a-f]*')
                    AND NEW.fingerprint_key_id IS NOT NULL AND NEW.fingerprint_key_id <> '' AND NEW.fingerprint_version IS NOT NULL AND NEW.fingerprint_version >= 1))))
              OR (OLD.retention_deleted_at IS NULL AND NEW.retention_deleted_at IS NULL AND (NEW.retention_expires_at IS NOT OLD.retention_expires_at
                OR NEW.retention_size_bytes IS NOT OLD.retention_size_bytes OR NEW.fingerprint IS NOT OLD.fingerprint OR NEW.fingerprint_key_id IS NOT OLD.fingerprint_key_id
                OR NEW.fingerprint_version IS NOT OLD.fingerprint_version))
            BEGIN SELECT RAISE(ABORT, 'invalid run event retention transition'); END
            SQL);
    }

    public function down(): void
    {
        if (DB::table('run_artifacts')->where('retention_state', 'deleted')->exists()) {
            throw new RuntimeException('The legacy run-artifact schema cannot represent retention tombstones.');
        }
        if (DB::table('check_results')->whereNotNull('retention_deleted_at')->exists()) {
            throw new RuntimeException('The legacy check-result guard cannot represent retention tombstones.');
        }
        if (DB::table('run_events')->whereNotNull('retention_deleted_at')->exists()) {
            throw new RuntimeException('The legacy run-event schema cannot represent retention tombstones.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS run_events_retention_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_events_retention_insert_guard');
        $checkGuard = $this->trigger('check_results_update_guard');
        $checkGuard = $this->replaceOnce($checkGuard, self::CHECK_GUARD_TAIL_NEW, self::CHECK_GUARD_TAIL_OLD);
        $checkGuard = $this->replaceOnce($checkGuard, self::CHECK_GUARD_HEAD_NEW, self::CHECK_GUARD_HEAD_OLD);
        DB::unprepared('DROP TRIGGER check_results_update_guard');
        DB::unprepared($checkGuard);
        $checkInsertGuard = $this->trigger('check_results_insert_guard');
        $checkInsertGuard = $this->replaceOnce($checkInsertGuard, self::CHECK_INSERT_NEW, self::CHECK_INSERT_OLD);
        DB::unprepared('DROP TRIGGER check_results_insert_guard');
        DB::unprepared($checkInsertGuard);
        foreach (['run_events', 'check_results'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['retention_expires_at', 'retention_deleted_at', 'retention_size_bytes', 'fingerprint_version', 'fingerprint_key_id', 'fingerprint']);
            });
        }

        $insertGuard = $this->trigger('run_artifacts_insert_guard');
        $insertGuard = $this->replaceOnce($insertGuard, self::ARTIFACT_KINDS_NEW, self::ARTIFACT_KINDS_OLD);
        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_METADATA_NEW, self::INSERT_METADATA_OLD);
        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_REFERENCE_NEW, self::INSERT_REFERENCE_OLD);
        $insertGuard = $this->replaceOnce($insertGuard, self::INSERT_DIGEST_NEW, self::INSERT_DIGEST_OLD);

        // Both artifact guards name the columns that disappear below; every
        // guard that references the table is captured and dropped before the
        // rebuilds and restored afterwards, the update guard for good.
        $referencingGuards = $this->triggersReferencing('run_artifacts');
        foreach (array_keys($referencingGuards) as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        Schema::table('run_artifacts', function (Blueprint $table): void {
            $table->dropIndex('run_artifacts_retention');
            $table->dropColumn(['retention_state', 'deleted_at', 'fingerprint_version', 'fingerprint_key_id', 'fingerprint']);
        });
        Schema::table('run_artifacts', function (Blueprint $table): void {
            $table->char('digest', 64)->nullable(false)->change();
            $table->string('storage_reference')->nullable(false)->change();
        });
        foreach ($referencingGuards as $name => $sql) {
            if (! in_array($name, ['run_artifacts_insert_guard', 'run_artifacts_update_guard'], true)) {
                DB::unprepared($sql);
            }
        }
        DB::unprepared($insertGuard);
    }

    private function bindLegacyRows(string $table, string $textColumn, RetentionCategory $category): void
    {
        $days = app(RetentionPolicy::class)->limit($category)->maxDays;
        DB::table($table)->whereNull('retention_expires_at')->whereNotNull('created_at')->update([
            'retention_expires_at' => DB::raw("datetime(created_at, '+".$days." days')"),
            'retention_size_bytes' => DB::raw('length(CAST('.$textColumn.' AS BLOB))'),
        ]);
    }

    private function trigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! property_exists($row, 'sql') || ! is_string($row->sql)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }

        return $row->sql;
    }

    /** @return array<string, string> */
    private function triggersReferencing(string $table): array
    {
        $guards = [];
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND sql LIKE ? ORDER BY name", ['%'.$table.'%']) as $row) {
            if (! is_object($row) || ! property_exists($row, 'name') || ! property_exists($row, 'sql')
                || ! is_string($row->name) || ! is_string($row->sql)) {
                throw new RuntimeException('The published SQLite guards referencing '.$table.' could not be read.');
            }
            $guards[$row->name] = $row->sql;
        }
        if (! array_key_exists('run_artifacts_insert_guard', $guards)) {
            throw new RuntimeException('The published SQLite guard run_artifacts_insert_guard is missing.');
        }

        return $guards;
    }

    private function replaceOnce(string $sql, string $old, string $new): string
    {
        $sql = str_replace($old, $new, $sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The run-artifact retention guards could not be extended deterministically.');
        }

        return $sql;
    }
};
