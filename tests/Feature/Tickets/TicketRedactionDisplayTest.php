<?php

namespace Tests\Feature\Tickets;

use App\AI6\Projects\Models\TicketReadModel;

final class TicketRedactionDisplayTest extends TicketUiTestCase
{
    public function test_clear_content_renders_safely_without_changing_the_stored_contract_content(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $content = $this->validTicketMarkdown('T3', 'ready', '[]', 'Unverändertes Ziel mit **Auszeichnung**.');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/T3.md', $content);

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('<strong>Auszeichnung</strong>', false);
        $response->assertSee('Unmaskiert');
        $response->assertDontSee('Inhalt maskiert');
        $response->assertSee('data-ai6-entry="edit"', false);
        $response->assertDontSee('data-ai6-entry="approval"', false);

        $stored = TicketReadModel::query()->findOrFail($readModel->getKey());
        self::assertSame($content, $stored->redacted_content, 'Presentation must never rewrite the stored contract content.');
    }

    public function test_masked_content_is_readably_marked_and_owns_no_edit_or_approval_entry(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $maskedContent = $this->validTicketMarkdown('R2', 'ready', '[]', 'Zugang über [REDACTED:SECRET] dokumentiert.');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/R2.md', $maskedContent, [
            'redaction_state' => 'content_redacted',
            'redaction_matches' => $this->redactionMatchFixture(),
            'source_blockers' => ['content_redacted'],
        ]);

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee('Inhalt maskiert');
        $response->assertSee('niemals als vollständig geprüfter');
        $response->assertSee('[REDACTED:SECRET]');
        $response->assertDontSee('data-ai6-entry', false);
    }
}
