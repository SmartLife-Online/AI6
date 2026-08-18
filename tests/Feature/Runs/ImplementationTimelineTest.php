<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Runs\RunImplementation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ImplementationTimelineTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    /** TC-13 */
    public function test_the_timeline_shows_redacted_changes_decisions_and_session_state(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC13');
        $adapter = new class implements AgentAdapter
        {
            public function result(AgentResultContext $context): string
            {
                $document = json_decode((new FakeAgentAdapter)->result($context), true, 16, JSON_THROW_ON_ERROR);
                $document['summary'] = 'Ergebnis mit sk-live-testsecret.';
                $document['decisions'][0]['rationale'] = 'Begründung sk-live-testsecret.';

                return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
            {
                file_put_contents(
                    rtrim(str_replace('\\', '/', $isolatedTree), '/').'/app/Example.php',
                    "<?php\n\n// fake-agent-change\n",
                );

                return $this->result($context);
            }
        };
        $this->app->instance(AgentAdapter::class, $adapter);
        $this->app->forgetInstance(RunImplementation::class);
        $this->executeImplement($prepared['run']);

        $response = $this->actingAs($prepared['operator'])
            ->get(route('projects.runs.show', [$prepared['project'], $prepared['run']->id]));
        $response->assertOk();
        $response->assertSee('data-changed-path="app/Example.php"', false);
        $response->assertSee('data-change-type="modified"', false);
        $response->assertSee('data-decision-key="d1"', false);
        $response->assertSee('data-session-state="bound"', false);
        $response->assertSee('Sitzung gebunden');
        $response->assertDontSee('sk-live-testsecret');
        $response->assertSee('[REDACTED:TOKEN]');
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        self::assertSame(0, DB::table('jobs')->count());

        $body = (string) $response->getContent();
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        self::assertSame(0, preg_match('/<table|width\s*:\s*\d{4,}px/i', $body));
    }
}
