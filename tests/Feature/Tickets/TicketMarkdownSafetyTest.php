<?php

namespace Tests\Feature\Tickets;

final class TicketMarkdownSafetyTest extends TicketUiTestCase
{
    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    public function test_hostile_ticket_markdown_is_neutralized_and_the_csp_stays_strict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $goal = 'Feindliches Ziel. <script>alert("ticket-xss")</script> '
            .'<iframe src="https://evil.example"></iframe> '
            .'[Klick mich](javascript:alert("link-xss")) Ende.';
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/X1.md',
            $this->validTicketMarkdown('X1', 'todo', '[]', $goal),
        );

        $detail = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));
        $detail->assertOk();
        $detail->assertSee('Feindliches Ziel.');
        $detail->assertDontSee('<script>alert("ticket-xss")</script>', false);
        $detail->assertDontSee('<iframe', false);
        $detail->assertDontSee('href="javascript:', false);
        $detail->assertDontSee("href='javascript:", false);
        $detail->assertHeader('Content-Security-Policy', self::STRICT_POLICY);

        $list = $this->actingAs($administrator)->get(route('projects.tickets.index', $project));
        $list->assertOk();
        $list->assertDontSee('<script>alert("ticket-xss")</script>', false);
        $list->assertDontSee('<iframe', false);
        $list->assertDontSee('href="javascript:', false);
        $list->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
    }
}
