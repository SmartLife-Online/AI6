<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RecordedScopeRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RecordedScopeRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
    }

    public function test_recorded_scope_is_deterministic_and_replaces_its_previous_bytes(): void
    {
        $run = new Run([
            'id' => '2f1d4a3c-0000-4000-8000-000000000029',
            'project_id' => 29,
            'scope_snapshot' => ['ticket_files' => ['tests/', 'ghp_1234567890', 'app/']],
            'agent_profile_snapshot' => ['limits' => config('ai6.project_config.server_defaults.limits')],
            'added_scope_paths_count' => 2,
        ]);
        $input = "---\nstatus: in_progress\n---\n\n# Test\n\n## Goal\n\nZiel.\n\n## Notes\n\nNotiz.\n";
        $renderer = $this->app->make(RecordedScopeRenderer::class);

        $first = $renderer->write($run, $input);
        $second = $renderer->write($run, $first);

        self::assertSame($first, $second);
        self::assertSame(1, substr_count($first, '## Recorded Scope'));
        self::assertStringContainsString('- `app/`', $first);
        self::assertStringContainsString('- `tests/`', $first);
        self::assertStringContainsString('[redigiert]', $first);
        self::assertStringNotContainsString('ghp_1234567890', $first);
        self::assertStringNotContainsString('[REDACTED:', $first);
        self::assertStringContainsString('**Pfadlimit:** 2 von ', $first);
        self::assertLessThan(strpos($first, '## Notes'), strpos($first, '## Recorded Scope'));
    }
}
