<?php

namespace Tests\Feature\Projects;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\Feature\BrowserSmokeTestHarness;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ProjectQueueMobileBrowserSmokeTest extends TicketUiTestCase
{
    use BrowserSmokeTestHarness;
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_queue_route_has_no_horizontal_scroll_at_smartphone_width(): void
    {
        $chromedriverBinary = $this->requireBrowserSmokeChromedriver('The project queue mobile browser smoke test');
        $password = bin2hex(random_bytes(16));
        [$user, $secret, $project] = $this->seedFileDatabase($password);
        $appPort = $this->freePort();
        $driverPort = $this->freePort();
        $baseUrl = 'http://ai6-smoke.test:'.$appPort;

        try {
            $this->startApplicationServer($appPort);
            $this->startChromedriver($chromedriverBinary, $driverPort);
            $this->createBrowserSession();
            $this->navigate($baseUrl.'/login');
            $this->type('#email', (string) $user->email);
            $this->type('input[type=password]', $password."\u{E007}");
            $this->waitForUrlContaining('/auth/factor');
            $this->type('input[inputmode=numeric]', $this->currentTotpCode($secret)."\u{E007}");
            $this->waitForUrlContaining('/projects');

            $this->navigate($baseUrl.'/projects/'.$project->getKey().'/queue');
            $this->waitForSourceContaining('QUEUE-MOBILE-1');
            $this->assertNoHorizontalScrolling('Projektqueue');
            $this->assertConsoleFreeOfPolicyViolations();
        } finally {
            $this->tearDownBrowserSmokeHarness();
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserSmokeHarness();
        parent::tearDown();
    }

    /** @return array{User, string, Project} */
    private function seedFileDatabase(string $password): array
    {
        $this->initializeBrowserSmokeDatabase('ai6-project-queue-browser-smoke');
        $fixture = $this->completedApproval('QUEUE-MOBILE-1');
        $user = $fixture['operator'];
        $user->forceFill(['email' => 'queue-smoke@example.test', 'password' => $password])->save();
        $secret = $this->createConfirmedTotp($user);

        return [$user, $secret, $fixture['project']];
    }
}
