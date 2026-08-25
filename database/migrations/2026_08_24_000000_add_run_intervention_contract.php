<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runObjects = $this->sqliteObjects('runs');
        Schema::table('runs', function (Blueprint $table): void {
            $table->foreignUuid('pending_status_operation_id')->nullable()
                ->after('status_operation_id')->constrained('control_operations')->restrictOnDelete();
            $table->char('confirmed_branch_publication_oid', 64)->nullable()
                ->after('pending_status_operation_id');
        });
        $this->restoreSqliteObjects($runObjects);

        $runAgentObjects = $this->sqliteObjects('run_agents');
        Schema::table('run_agents', function (Blueprint $table): void {
            $table->uuid('approval_slot_id')->nullable()->after('slot_id');
            $table->unsignedInteger('slot_revision')->default(1)->after('approval_slot_id');
            $table->boolean('is_active')->default(true)->after('slot_revision');
        });
        $this->restoreSqliteObjects($runAgentObjects);

        Schema::create('run_limit_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('limit_name');
            $table->string('consumption_key');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('created_at');
            $table->unique(['run_id', 'limit_name', 'consumption_key'], 'run_limit_consumption_unique');
            $table->index(['run_id', 'limit_name']);
        });

        $interventionObjects = $this->sqliteObjects('interventions');
        Schema::table('interventions', function (Blueprint $table): void {
            $table->string('actor_role')->nullable()->after('user_id');
            $table->boolean('step_up_verified')->default(false)->after('actor_role');
            $table->char('step_up_proof_hash', 64)->nullable()->after('step_up_verified');
            $table->unsignedBigInteger('expected_run_version')->nullable()->after('chosen_option_key');
            $table->string('wait_reason')->nullable()->after('expected_run_version');
            $table->string('bound_step_key')->nullable()->after('wait_reason');
            $table->text('reason')->nullable()->after('bound_step_key');
            $table->char('idempotency_key', 64)->nullable()->after('reason');
            $table->foreignUuid('status_operation_id')->nullable()->after('idempotency_key')
                ->constrained('control_operations')->restrictOnDelete();
        });
        $this->restoreSqliteObjects($interventionObjects);
        DB::statement('CREATE UNIQUE INDEX interventions_idempotency_unique ON interventions (idempotency_key) WHERE idempotency_key IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_intervention_state_insert_guard BEFORE INSERT ON runs
            WHEN NEW.pending_status_operation_id IS NOT NULL
              OR NEW.confirmed_branch_publication_oid IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'invalid initial run intervention state'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_intervention_state_update_guard BEFORE UPDATE ON runs
            WHEN (NEW.pending_status_operation_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM control_operations operation
                    WHERE operation.id = NEW.pending_status_operation_id
                      AND operation.project_id = NEW.project_id
                      AND operation.operation_type = 'ticket_status_change'
                ))
              OR (NEW.confirmed_branch_publication_oid IS NOT NULL AND (
                    length(NEW.confirmed_branch_publication_oid) <> 64
                    OR NEW.confirmed_branch_publication_oid GLOB '*[^0-9a-f]*'
                ))
              OR (OLD.confirmed_branch_publication_oid IS NOT NULL
                    AND NEW.confirmed_branch_publication_oid IS NOT OLD.confirmed_branch_publication_oid)
            BEGIN SELECT RAISE(ABORT, 'invalid run intervention state'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_limit_consumptions_insert_guard BEFORE INSERT ON run_limit_consumptions
            WHEN NEW.limit_name NOT IN ('max_agent_invocations','max_review_rounds','max_fix_rounds','max_verification_rounds')
              OR NEW.quantity < 1 OR NEW.consumption_key = ''
            BEGIN SELECT RAISE(ABORT, 'invalid run limit consumption'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_limit_consumptions_update_guard BEFORE UPDATE ON run_limit_consumptions
            BEGIN SELECT RAISE(ABORT, 'run limit consumptions are immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER interventions_audit_insert_guard BEFORE INSERT ON interventions
            WHEN NEW.actor_role IS NULL OR NEW.actor_role NOT IN ('admin','operator','approver')
              OR NEW.step_up_verified NOT IN (0, 1)
              OR (NEW.step_up_verified = 0 AND NEW.step_up_proof_hash IS NOT NULL)
              OR (NEW.step_up_verified = 1 AND (NEW.step_up_proof_hash IS NULL
                    OR length(NEW.step_up_proof_hash) <> 64
                    OR NEW.step_up_proof_hash GLOB '*[^0-9a-f]*'))
              OR NEW.expected_run_version IS NULL OR NEW.expected_run_version < 1
              OR NEW.wait_reason IS NULL OR NEW.wait_reason NOT IN (
                    'human_question','scope_approval','contract_change','review_limit','resource_limit',
                    'provider_error','invalid_json','check_failure','git_base_changed','git_conflict'
                )
              OR NEW.bound_step_key IS NULL OR NEW.bound_step_key = ''
              OR NEW.reason IS NULL OR trim(NEW.reason) = ''
              OR NEW.idempotency_key IS NULL OR length(NEW.idempotency_key) <> 64
              OR NEW.idempotency_key GLOB '*[^0-9a-f]*'
            BEGIN SELECT RAISE(ABORT, 'invalid intervention audit'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER interventions_audit_update_guard BEFORE UPDATE ON interventions
            BEGIN SELECT RAISE(ABORT, 'interventions are immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER interventions_audit_delete_guard BEFORE DELETE ON interventions
            BEGIN SELECT RAISE(ABORT, 'interventions are immutable'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS interventions_audit_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS interventions_audit_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS interventions_audit_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_limit_consumptions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_limit_consumptions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_intervention_state_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_intervention_state_insert_guard');
        DB::unprepared('DROP INDEX IF EXISTS interventions_idempotency_unique');
        $interventionObjects = $this->sqliteObjects('interventions');
        $runAgentObjects = $this->sqliteObjects('run_agents');
        $runObjects = $this->sqliteObjects('runs');
        Schema::table('interventions', function (Blueprint $table): void {
            $table->dropForeign(['status_operation_id']);
            $table->dropColumn([
                'actor_role', 'step_up_verified', 'step_up_proof_hash', 'expected_run_version', 'wait_reason',
                'bound_step_key', 'reason', 'idempotency_key', 'status_operation_id',
            ]);
        });
        $this->restoreSqliteObjects($interventionObjects);
        Schema::dropIfExists('run_limit_consumptions');
        Schema::table('run_agents', function (Blueprint $table): void {
            $table->dropColumn(['approval_slot_id', 'slot_revision', 'is_active']);
        });
        $this->restoreSqliteObjects($runAgentObjects);
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropForeign(['pending_status_operation_id']);
            $table->dropColumn(['pending_status_operation_id', 'confirmed_branch_publication_oid']);
        });
        $this->restoreSqliteObjects($runObjects);
    }

    /** @return list<array{name: string, sql: string}> */
    private function sqliteObjects(string $table): array
    {
        return array_values(array_filter(array_map(
            static fn (object $row): ?array => is_string($row->name ?? null) && is_string($row->sql ?? null)
                ? ['name' => $row->name, 'sql' => $row->sql]
                : null,
            DB::select("SELECT name, sql FROM sqlite_master WHERE tbl_name = ? AND type IN ('index', 'trigger')", [$table]),
        )));
    }

    /** @param list<array{name: string, sql: string}> $objects */
    private function restoreSqliteObjects(array $objects): void
    {
        foreach ($objects as $object) {
            $exists = DB::selectOne('SELECT 1 AS present FROM sqlite_master WHERE name = ?', [$object['name']]);
            if (! is_object($exists)) {
                DB::unprepared($object['sql']);
            }
        }
    }
};
