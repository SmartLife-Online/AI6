<?php

namespace Tests\Feature\Tickets;

use App\AI6\Projects\ProjectReadModelStatus;
use App\AI6\Tickets\Livewire\TicketList;
use App\AI6\Tickets\TicketViewModelFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

final class TicketListViewTest extends TicketUiTestCase
{
    public function test_list_shows_required_fields_without_expanding_and_reads_only_the_read_model(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        // The fixture content exists exclusively as a published read model row;
        // this test environment has no managed clone at all, so every rendered
        // value below proves the read model as the only data source.
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'done', '[]', 'Erstes Ziel sichtbar ohne Aufklappen.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T2.md',
            $this->validTicketMarkdown('T2', 'todo', '[T1]', 'Zweites Ziel sichtbar ohne Aufklappen.'),
        );

        $response = $this->actingAs($administrator)->get(route('projects.tickets.index', $project));

        $response->assertOk();
        $response->assertSee('T1');
        $response->assertSee('Ticket T1');
        $response->assertSee('done');
        $response->assertSee('Erstes Ziel sichtbar ohne Aufklappen.');
        $response->assertSee('T2');
        $response->assertSee('todo');
        $response->assertSee('Zweites Ziel sichtbar ohne Aufklappen.');
        $response->assertDontSee('<details', false);
    }

    public function test_status_and_validation_state_filters_change_the_result_set(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'done', '[]', 'Fertiges Ticket.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T2.md',
            $this->validTicketMarkdown('T2', 'todo', '[]', 'Offenes Ticket.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/B1.md',
            $this->validTicketMarkdown('B1', 'wip', '[]', 'Unzulässiger Status.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/R1.md',
            $this->validTicketMarkdown('R1', 'todo', '[]', 'Maskiertes Ticket.'),
            [
                'redaction_state' => 'content_redacted',
                'redaction_matches' => $this->redactionMatchFixture(),
                'source_blockers' => ['content_redacted'],
            ],
        );

        Livewire::actingAs($administrator);
        Livewire::test(TicketList::class, ['project' => $project])
            ->assertSee('Fertiges Ticket.')
            ->assertSee('Offenes Ticket.')
            ->assertSee('Unzulässiger Status.')
            ->assertSee('Maskiertes Ticket.')
            ->set('status', 'done')
            ->assertSee('Fertiges Ticket.')
            ->assertDontSee('Offenes Ticket.')
            ->assertDontSee('Unzulässiger Status.')
            ->set('status', '')
            ->set('state', 'invalid')
            ->assertSee('Unzulässiger Status.')
            ->assertDontSee('Fertiges Ticket.')
            ->assertDontSee('Offenes Ticket.')
            ->set('state', 'content_redacted')
            ->assertSee('Maskiertes Ticket.')
            ->assertDontSee('Fertiges Ticket.')
            ->set('state', 'valid')
            ->assertSee('Fertiges Ticket.')
            ->assertSee('Offenes Ticket.')
            ->assertDontSee('Unzulässiger Status.');
    }

    public function test_list_and_project_status_resolve_effective_configuration_once_per_render(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);

        foreach (['T1', 'T2', 'T3'] as $ticketId) {
            $this->publishReadModel(
                $administrator,
                $project,
                'tickets/'.$ticketId.'.md',
                $this->validTicketMarkdown($ticketId, 'todo', '[]', 'Konstante Konfigurationsabfragen.'),
            );
        }

        $configurationQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$configurationQueries): void {
            if (str_contains($query->sql, 'project_config_drafts')
                || str_contains($query->sql, 'project_config_snapshots')) {
                $configurationQueries[] = $query->sql;
            }
        });

        $this->app->make(TicketViewModelFactory::class)->listFor($project->refresh());
        self::assertCount(2, $configurationQueries);

        $configurationQueries = [];
        $this->app->make(ProjectReadModelStatus::class)->for($project->refresh());
        self::assertCount(2, $configurationQueries);
    }
}
