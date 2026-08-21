<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_gates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('run_id')->constrained('runs')->cascadeOnDelete();
            $table->string('gate_id', 16);
            $table->string('kind');
            $table->string('state')->default('open');
            $table->boolean('blocks_candidate')->default(true);
            $table->boolean('blocks_final_commit')->default(true);
            $table->boolean('blocks_push')->default(true);
            $table->text('evidence_reference')->nullable();
            $table->char('evidence_ticket_contract_sha256', 64)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->char('ticket_contract_sha256', 64);
            $table->char('checkpoint_commit_sha', 64)->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'gate_id']);
            $table->index(['run_id', 'state']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_gates_insert_guard BEFORE INSERT ON run_gates
            WHEN (NEW.gate_id NOT GLOB 'MG-[0-9][0-9]' AND NEW.gate_id NOT GLOB 'EXT-[0-9][0-9]')
              OR (NEW.kind = 'manual') <> (NEW.gate_id GLOB 'MG-[0-9][0-9]')
              OR (NEW.kind = 'external') <> (NEW.gate_id GLOB 'EXT-[0-9][0-9]')
              OR NEW.kind NOT IN ('manual', 'external') OR NEW.state <> 'open'
              OR NEW.blocks_candidate <> 1 OR NEW.blocks_final_commit <> 1 OR NEW.blocks_push <> 1
              OR NEW.evidence_reference IS NOT NULL OR NEW.evidence_ticket_contract_sha256 IS NOT NULL OR NEW.authorized_by IS NOT NULL OR NEW.authorized_at IS NOT NULL
              OR NEW.checkpoint_commit_sha IS NOT NULL OR NEW.invalidated_at IS NOT NULL
              OR length(NEW.ticket_contract_sha256) <> 64 OR NEW.ticket_contract_sha256 GLOB '*[^0-9a-f]*'
            BEGIN SELECT RAISE(ABORT, 'invalid run gate'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER run_gates_update_guard BEFORE UPDATE ON run_gates
            WHEN NEW.id <> OLD.id OR NEW.run_id <> OLD.run_id OR NEW.gate_id <> OLD.gate_id OR NEW.kind <> OLD.kind
              OR NEW.blocks_candidate <> 1 OR NEW.blocks_final_commit <> 1 OR NEW.blocks_push <> 1
              OR NEW.state NOT IN ('open', 'closed')
              OR (NEW.state = 'closed' AND (NEW.evidence_reference IS NULL OR NEW.evidence_reference = ''
                  OR NEW.evidence_ticket_contract_sha256 IS NULL OR NEW.evidence_ticket_contract_sha256 <> NEW.ticket_contract_sha256
                  OR NEW.authorized_by IS NULL OR NEW.authorized_at IS NULL OR NEW.checkpoint_commit_sha IS NULL
                  OR NEW.invalidated_at IS NOT NULL))
              OR (OLD.state = 'closed' AND NEW.state = 'open' AND NEW.invalidated_at IS NULL)
              OR (NEW.evidence_ticket_contract_sha256 IS NOT NULL AND (length(NEW.evidence_ticket_contract_sha256) <> 64 OR NEW.evidence_ticket_contract_sha256 GLOB '*[^0-9a-f]*'))
              OR (NEW.checkpoint_commit_sha IS NOT NULL AND (length(NEW.checkpoint_commit_sha) <> 64 OR NEW.checkpoint_commit_sha GLOB '*[^0-9a-f]*'))
            BEGIN SELECT RAISE(ABORT, 'invalid run gate transition'); END
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS run_gates_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS run_gates_insert_guard');
        Schema::dropIfExists('run_gates');
    }
};
