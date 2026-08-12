<?php

namespace Tests\Feature\Tickets;

use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ProjectOperationLease;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TicketUiArchitectureTest extends TicketUiTestCase
{
    private const FORBIDDEN_REFERENCES = [
        'HardenedGit',
        'ManagedProjectPath',
        'ControlProcessRunner',
        'ProcessRequest',
        'Symfony\Component\Process',
        'proc_open(',
        'shell_exec(',
        'passthru(',
        'popen(',
    ];

    public function test_the_ticket_ui_reaches_neither_the_hardened_git_seam_nor_the_managed_clone(): void
    {
        $sources = [
            app_path('AI6/Tickets/Livewire/TicketList.php'),
            app_path('AI6/Tickets/Livewire/TicketDetail.php'),
            app_path('AI6/Tickets/TicketMutationController.php'),
            app_path('AI6/Tickets/TicketViewModel.php'),
            app_path('AI6/Tickets/TicketViewModelFactory.php'),
            app_path('AI6/Tickets/DependencyBadge.php'),
            ...$this->ticketViewFiles(),
        ];

        foreach ($sources as $path) {
            self::assertFileExists($path);
            $source = file_get_contents($path);
            self::assertIsString($source);

            foreach (self::FORBIDDEN_REFERENCES as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $source,
                    sprintf('%s must not reach the process or managed-clone seam via "%s".', $path, $forbidden),
                );
            }
        }
    }

    public function test_running_and_failed_refresh_operations_stay_visible_without_internal_error_bodies(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $internalError = 'Interner-Testbefund-'.bin2hex(random_bytes(6));
        $failed = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/F1.md',
            $this->validTicketMarkdown('F1', 'todo', '[]', 'Fehlgeschlagener Auftrag.'),
            finishOperation: false,
        );
        $failedOperation = $failed->operation()->firstOrFail();
        $this->finishOperation(
            $failedOperation,
            (int) $failedOperation->current_attempt_token,
            ControlOperationState::FAILED,
            $internalError,
        );
        $running = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/L1.md',
            $this->validTicketMarkdown('L1', 'todo', '[]', 'Laufender Auftrag.'),
            finishOperation: false,
        );

        $list = $this->actingAs($administrator)->get(route('projects.tickets.index', $project));
        $list->assertOk();
        $list->assertSee('Auftrag läuft');
        $list->assertSee('Auftrag fehlgeschlagen');
        $list->assertDontSee($internalError);

        $runningDetail = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $running]));
        $runningDetail->assertOk();
        $runningDetail->assertSee('Auftrag läuft');

        $failedDetail = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $failed]));
        $failedDetail->assertOk();
        $failedDetail->assertSee('Auftrag fehlgeschlagen');
        $failedDetail->assertDontSee($internalError);
    }

    public function test_refresh_operations_without_projection_appear_as_safe_envelope_entries(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $internalError = 'Vorpersistenz-Befund-'.bin2hex(random_bytes(6));
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'todo', '[]', 'Reguläre Projektion.'),
        );

        // A refresh that failed before the first read model was persisted.
        $failedOperation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/P8.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)
            ->claim($failedOperation, str_repeat('b', 32));
        self::assertIsInt($attemptToken);
        $this->finishOperation($failedOperation->refresh(), $attemptToken, ControlOperationState::FAILED, $internalError);

        // A freshly queued refresh whose projection does not exist yet.
        $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/P9.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();

        $list = $this->actingAs($administrator)->get(route('projects.tickets.index', $project));
        $list->assertOk();
        $list->assertSee('Refresh-Aufträge ohne Projektion');
        $list->assertSee('tickets/P8.md');
        $list->assertSee('tickets/P9.md');
        $list->assertSee('Auftrag läuft');
        $list->assertSee('Auftrag fehlgeschlagen');
        $list->assertDontSee($internalError);
    }

    public function test_a_running_order_in_the_same_second_outranks_a_terminal_one(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        Date::setTestNow(Date::now()->startOfSecond());

        try {
            // The completed operation carries the lexically larger identifier,
            // so a pure id tiebreak would wrongly hide the queued successor.
            $this->publishReadModel(
                $administrator,
                $project,
                'tickets/T1.md',
                $this->validTicketMarkdown('T1', 'todo', '[]', 'Gleichsekundenziel.'),
                operationId: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            );
            $this->app->make(QueueTicketReadModelRefresh::class)->handle(
                $administrator,
                $project->refresh(),
                'tickets/T1.md',
                'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            );
            DB::table('jobs')->delete();

            $refreshQueries = [];
            DB::listen(static function (QueryExecuted $query) use (&$refreshQueries): void {
                if (str_contains($query->sql, 'ranked_ticket_refresh_operations')) {
                    $refreshQueries[] = strtolower($query->sql);
                }
            });

            $list = $this->actingAs($administrator)->get(route('projects.tickets.index', $project));
            $list->assertOk();
            $list->assertSee('Auftrag läuft');
            $list->assertSee('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
            self::assertNotSame([], $refreshQueries);
            self::assertStringContainsString('row_number() over', $refreshQueries[0]);
            self::assertStringContainsString('"refresh_rank" = ?', $refreshQueries[0]);
        } finally {
            Date::setTestNow();
        }
    }

    /** @return list<string> */
    private function ticketViewFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views/tickets'), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        self::assertNotSame([], $files);

        return $files;
    }
}
