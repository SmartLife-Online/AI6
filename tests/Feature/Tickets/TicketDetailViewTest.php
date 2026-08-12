<?php

namespace Tests\Feature\Tickets;

final class TicketDetailViewTest extends TicketUiTestCase
{
    public function test_invalid_projection_shows_structured_errors_and_a_repairable_marker_without_approval(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/B1.md',
            $this->validTicketMarkdown('B1', 'wip', '[]', 'Ziel mit unzulässigem Status.'),
        );

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('Strukturierte Validierungsfehler');
        $response->assertSee('status_invalid');
        $response->assertSee('Der Ticketstatus ist nicht zulässig.');
        $response->assertSee('Reparierbar');
        $response->assertSee('data-ai6-entry="edit"', false);
        $response->assertDontSee('data-ai6-entry="approval"', false);
    }

    public function test_unparsed_projection_shows_only_the_envelope_and_refresh_state(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $readModel = $this->publishUnparsedReadModel(
            $administrator,
            $project,
            'tickets/U1.md',
            $this->validTicketMarkdown('U1', 'todo', '[]', 'Unsichtbares Envelope-Ziel.'),
            [],
            false,
        );

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('Diese Projektion ist noch nicht geparst.');
        $response->assertSee('unparsed');
        $response->assertSee($readModel->control_commit);
        $response->assertSee($readModel->blob_sha);
        $response->assertSee('Auftrag läuft');
        $response->assertDontSee('Unsichtbares Envelope-Ziel.');
        $response->assertDontSee('Strukturierte Validierungsfehler');
        $response->assertDontSee('data-ai6-entry', false);
    }

    public function test_valid_projection_shows_required_fields_and_safely_rendered_markdown(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T7.md',
            $this->validTicketMarkdown('T7', 'ready', '[]', 'Ziel mit **hervorgehobenem** Markdown.'),
        );

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('T7');
        $response->assertSee('Ticket T7');
        $response->assertSee('ready');
        $response->assertSee('<strong>hervorgehobenem</strong>', false);
        $response->assertSee($readModel->ticket_contract_sha256);
        $response->assertSee('data-ai6-entry="edit"', false);
        $response->assertSee('data-ai6-entry="approval"', false);
    }
}
