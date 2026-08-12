<?php

namespace Tests\Feature\Tickets;

final class TicketDependencyBadgeTest extends TicketUiTestCase
{
    public function test_satisfied_open_missing_and_unknown_dependencies_render_their_badges(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/S1.md',
            $this->validTicketMarkdown('S1', 'done', '[]', 'Erfüllte Abhängigkeit.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/O1.md',
            $this->validTicketMarkdown('O1', 'todo', '[]', 'Offene Abhängigkeit.'),
        );
        // U7 parses with a readable id but carries no status field: the target
        // exists in the read-model inventory while its status stays unknown.
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/U7.md',
            <<<'MARKDOWN'
            ---
            schema: ai6.ticket.v1
            id: U7
            title: "Ticket ohne Status"
            depends_on: []
            ---

            # U7 — Ticket ohne Status

            ## Goal

            Statusfreies Ziel.
            MARKDOWN,
        );
        // U8 exists only as an unparsed envelope: the canonical filename still
        // lets the declared dependency point at it, but nothing is readable.
        $this->publishUnparsedReadModel(
            $administrator,
            $project,
            'tickets/U8.md',
            $this->validTicketMarkdown('U8', 'todo', '[]', 'Unlesbares Envelope-Ziel.'),
        );
        // Two projections both declare the id D2 with conflicting statuses;
        // the badge must surface the ambiguity instead of adopting either.
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/Y1.md',
            $this->validTicketMarkdown('D2', 'done', '[]', 'Erster Doppelgänger.'),
        );
        $this->publishReadModel(
            $administrator,
            $project,
            'tickets/Y2.md',
            $this->validTicketMarkdown('D2', 'todo', '[]', 'Zweiter Doppelgänger.'),
        );
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/D1.md',
            $this->validTicketMarkdown('D1', 'todo', '[S1, O1, M9, U7, U8, D2]', 'Ticket mit Abhängigkeitsmatrix.'),
        );

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('S1 – done');
        $response->assertSee('O1 – todo');
        $response->assertSee('M9 – fehlt im Ticketbestand');
        $response->assertSee('U7 – Status unbekannt');
        $response->assertSee('U8 – vorhanden, aber nicht lesbar');
        $response->assertDontSee('U8 – fehlt im Ticketbestand');
        $response->assertSee('D2 – mehrdeutig im Ticketbestand');
        $response->assertDontSee('D2 – done');
        $response->assertDontSee('D2 – todo');
    }
}
