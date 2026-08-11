<?php

namespace Tests\Feature\Tickets;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Git\ControlOperationTestCase;

final class TicketProjectionSchemaTest extends ControlOperationTestCase
{
    public function test_validation_projection_columns_and_worker_only_command_are_registered(): void
    {
        foreach (['validation_errors', 'editor_eligible', 'approval_eligible'] as $column) {
            self::assertTrue(Schema::hasColumn('ticket_read_models', $column));
        }
        config(['ai6.runtime_role' => 'app']);
        self::assertSame(1, Artisan::call('ai6:tickets:reproject-unparsed'));
        self::assertStringContainsString('Worker-Kontext', Artisan::output());
    }

    public function test_projection_migration_keeps_insert_fenced_and_restores_the_complete_bootstrap_guard(): void
    {
        $migration = require database_path('migrations/2026_08_11_000000_add_ticket_validation_projection_contract.php');
        $insert = $this->trigger('ticket_read_models_insert_guard');
        $update = $this->trigger('ticket_read_models_update_guard');
        self::assertStringContainsString("state = 'running'", $insert);
        self::assertStringNotContainsString("state = 'completed'", $insert);
        self::assertStringContainsString("state = 'completed'", $update);
        self::assertStringContainsString("OLD.document_state = 'unparsed'", $update);
        self::assertStringContainsString('NEW.control_commit = OLD.control_commit', $update);
        self::assertStringContainsString('NEW.blob_sha = OLD.blob_sha', $update);

        $migration->down();
        $bootstrapInsert = $this->trigger('ticket_read_models_insert_guard');
        $bootstrapUpdate = $this->trigger('ticket_read_models_update_guard');
        foreach ([$bootstrapInsert, $bootstrapUpdate] as $guard) {
            self::assertStringContainsString('json_each(NEW.redaction_matches)', $guard);
            self::assertStringContainsString("json_type(value, '$.fingerprint') <> 'text'", $guard);
            self::assertStringNotContainsString("state = 'completed'", $guard);
        }

        $migration->up();
        self::assertTrue(Schema::hasColumn('ticket_read_models', 'validation_errors'));
    }

    private function trigger(string $name): string
    {
        return (string) DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', $name)
            ->value('sql');
    }
}
