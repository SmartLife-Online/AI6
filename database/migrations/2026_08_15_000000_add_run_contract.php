<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PRE_RUN_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh', 'ticket_approval'";

    private const RUN_TYPES = "'deploy_key_provision', 'managed_clone', 'managed_fetch', 'control_branch_change', 'ticket_refresh', 'ticket_edit', 'ticket_status_change', 'config_refresh', 'ticket_approval', 'run_start'";

    private const PRE_RUN_MUTATIONS = "('ticket_edit', 'ticket_status_change', 'ticket_approval')";

    private const RUN_MUTATIONS = "('ticket_edit', 'ticket_status_change', 'ticket_approval', 'run_start')";

    /** @var array<string, string> */
    private array $projectRegistrationGuards = [];

    public function up(): void
    {
        $this->rewriteRunStartGuards(true);
        $this->captureProjectRegistrationGuards();

        Schema::create('runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ticket_approval_id')->unique()->constrained('ticket_approvals')->restrictOnDelete();
            $table->foreignUuid('status_operation_id')->unique()->constrained('control_operations')->restrictOnDelete();
            $table->string('run_type')->default('implementation');
            $table->string('state');
            $table->string('phase');
            $table->string('wait_reason')->nullable();
            $table->char('claim_parent_control_sha', 64);
            $table->char('initial_run_base_sha', 64);
            $table->char('run_base_sha', 64);
            $table->json('config_snapshot');
            $table->char('config_hash', 64);
            $table->json('scope_snapshot');
            $table->char('scope_hash', 64);
            $table->json('prompt_snapshot');
            $table->char('prompt_hash', 64);
            $table->json('instruction_snapshot');
            $table->char('instruction_hash', 64);
            $table->json('runtime_profile_snapshot');
            $table->char('runtime_profile_hash', 64);
            $table->json('agent_profile_snapshot');
            $table->char('agent_profile_hash', 64);
            $table->char('security_policy_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['project_id', 'state']);
        });

        Schema::create('run_agents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->uuid('slot_id');
            $table->string('role');
            $table->string('provider_profile');
            $table->string('model');
            $table->string('effort');
            $table->string('prompt_profile');
            $table->string('session_id')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'slot_id']);
        });

        Schema::create('run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('event_type');
            $table->text('redacted_payload');
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignUuid('active_run_id')->nullable()->after('pending_control_operation_id')->constrained('runs')->nullOnDelete();
        });
        $this->recreateProjectRegistrationGuards();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_insert_guard BEFORE INSERT ON runs
            WHEN NEW.state <> 'queued' OR NEW.phase <> 'prepare' OR NEW.wait_reason IS NOT NULL
              OR NEW.version <> 1 OR NEW.initial_run_base_sha <> NEW.run_base_sha
              OR length(NEW.claim_parent_control_sha) <> 64 OR NEW.claim_parent_control_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.initial_run_base_sha) <> 64 OR NEW.initial_run_base_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.run_base_sha) <> 64 OR NEW.run_base_sha GLOB '*[^0-9a-f]*'
              OR NOT EXISTS (SELECT 1 FROM control_operations AS operation
                JOIN ticket_approvals AS approval ON approval.id = NEW.ticket_approval_id
                WHERE operation.id = NEW.status_operation_id AND operation.project_id = NEW.project_id
                  AND operation.operation_type = 'run_start'
                  AND json_extract(operation.operation_parameters_jcs, '$.approval_id') = approval.id
                  AND approval.project_id = NEW.project_id)
            BEGIN SELECT RAISE(ABORT, 'invalid run insert'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER runs_update_guard BEFORE UPDATE ON runs
            WHEN NEW.project_id <> OLD.project_id OR NEW.ticket_approval_id <> OLD.ticket_approval_id
              OR NEW.status_operation_id <> OLD.status_operation_id OR NEW.claim_parent_control_sha <> OLD.claim_parent_control_sha
              OR NEW.initial_run_base_sha <> OLD.initial_run_base_sha OR NEW.version <> OLD.version + 1
              OR NEW.state NOT IN ('queued', 'running', 'waiting', 'failed', 'completed', 'cancelled')
              OR NEW.phase NOT IN ('prepare', 'implement', 'check', 'review', 'fix', 'finalize', 'security_review', 'publish')
              OR (NEW.wait_reason IS NOT NULL AND NEW.wait_reason NOT IN ('human_question', 'scope_approval', 'contract_change', 'review_limit', 'resource_limit', 'provider_error', 'invalid_json', 'check_failure', 'manual_gate', 'security_gate', 'git_base_changed', 'git_conflict', 'manual_push', 'manual_report', 'status_sync'))
              OR length(NEW.run_base_sha) <> 64 OR NEW.run_base_sha GLOB '*[^0-9a-f]*'
              OR ((NEW.state = 'waiting') <> (NEW.wait_reason IS NOT NULL))
            BEGIN SELECT RAISE(ABORT, 'invalid run transition'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS runs_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS runs_insert_guard');
        $this->captureProjectRegistrationGuards();
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_run_id');
        });
        $this->recreateProjectRegistrationGuards();
        Schema::dropIfExists('run_events');
        Schema::dropIfExists('run_agents');
        Schema::dropIfExists('runs');
        $this->rewriteRunStartGuards(false);
    }

    private function recreateProjectRegistrationGuards(): void
    {
        foreach ($this->projectRegistrationGuards as $name => $published) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
            DB::unprepared($published);
        }
        $this->projectRegistrationGuards = [];
    }

    private function captureProjectRegistrationGuards(): void
    {
        foreach (['projects_registration_metadata_insert', 'projects_registration_metadata_update'] as $name) {
            $this->projectRegistrationGuards[$name] = $this->trigger($name);
        }
    }

    private function trigger(string $name): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! property_exists($row, 'sql') || ! is_string($row->sql)) {
            throw new RuntimeException('The published SQLite guard '.$name.' is missing.');
        }

        return $row->sql;
    }

    private function rewriteRunStartGuards(bool $include): void
    {
        $oldTypes = $include ? self::PRE_RUN_TYPES : self::RUN_TYPES;
        $newTypes = $include ? self::RUN_TYPES : self::PRE_RUN_TYPES;
        $oldMutations = $include ? self::PRE_RUN_MUTATIONS : self::RUN_MUTATIONS;
        $newMutations = $include ? self::RUN_MUTATIONS : self::PRE_RUN_MUTATIONS;

        foreach (['control_operations_insert_guard', 'control_operations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace("NEW.operation_type NOT IN ($oldTypes)", "NEW.operation_type NOT IN ($newTypes)", $sql, $types);
            $sql = str_replace('NEW.operation_type IN '.$oldMutations, 'NEW.operation_type IN '.$newMutations, $sql, $mutations);
            if ($types !== 1 || $mutations !== 1) {
                throw new RuntimeException('The control-operation run-start guards could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }

        foreach (['ticket_read_models_insert_guard', 'ticket_read_models_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace('operation_type IN '.$oldMutations, 'operation_type IN '.$newMutations, $sql, $count);
            if ($count !== 1) {
                throw new RuntimeException('The ticket-read-model run-start guard could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }

        $oldTargets = $include
            ? "'todo', 'ready', 'blocked', 'cancelled', 'done'"
            : "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress'";
        $newTargets = $include
            ? "'todo', 'ready', 'blocked', 'cancelled', 'done', 'in_progress'"
            : "'todo', 'ready', 'blocked', 'cancelled', 'done'";
        $oldSources = $include
            ? "'todo', 'ready', 'blocked', 'review'"
            : "'todo', 'ready', 'blocked', 'review', 'in_progress'";
        $newSources = $include
            ? "'todo', 'ready', 'blocked', 'review', 'in_progress'"
            : "'todo', 'ready', 'blocked', 'review'";
        $inProgressSourceClause = "OR (NEW.source_status = 'in_progress' AND NOT EXISTS (SELECT 1 FROM control_operations WHERE id = NEW.status_operation_id AND operation_type = 'ticket_status_change'))";
        $oldSourceGuard = "NEW.source_status NOT IN ($oldSources)".($include ? '' : "\n                  $inProgressSourceClause");
        $newSourceGuard = "NEW.source_status NOT IN ($newSources)".($include ? "\n                  $inProgressSourceClause" : '');
        $approvalClause = "OR (operation_type = 'ticket_approval' AND json_extract(operation_parameters_jcs, '$.status_operation') = 'approve')";
        $runClause = "OR (operation_type = 'run_start' AND json_extract(operation_parameters_jcs, '$.relative_path') = NEW.relative_path)";
        $oldClause = $include ? $approvalClause : $approvalClause."\n                      ".$runClause;
        $newClause = $include ? $approvalClause."\n                      ".$runClause : $approvalClause;

        foreach (['ticket_mutations_insert_guard', 'ticket_mutations_update_guard'] as $name) {
            $sql = $this->trigger($name);
            $sql = str_replace('operation_type IN '.$oldMutations, 'operation_type IN '.$newMutations, $sql, $types);
            $sql = str_replace($oldSourceGuard, $newSourceGuard, $sql, $sources);
            $sql = str_replace("NEW.target_status NOT IN ($oldTargets)", "NEW.target_status NOT IN ($newTargets)", $sql, $targets);
            $sql = str_replace($oldClause, $newClause, $sql, $operation);
            if ($types !== 1 || $sources !== 1 || $targets !== 1 || $operation !== 1) {
                throw new RuntimeException('The ticket-mutation run-start guards could not be extended deterministically.');
            }
            $this->replaceTrigger($name, $sql);
        }
    }

    private function replaceTrigger(string $name, string $sql): void
    {
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
