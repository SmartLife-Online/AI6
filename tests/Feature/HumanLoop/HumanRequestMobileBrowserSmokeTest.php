<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\BrowserSmokeTestHarness;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-12: real mobile-viewport smoke for inbox and answer form behind an explicit
 * flag. Without the flag it skips and is never reported as executed.
 */
final class HumanRequestMobileBrowserSmokeTest extends TicketUiTestCase
{
    use BrowserSmokeTestHarness;
    use BuildsHumanRequestFixture;

    public function test_inbox_and_answer_form_do_not_scroll_horizontally_on_a_phone(): void
    {
        $chromedriverBinary = $this->requireBrowserSmokeChromedriver('The mobile browser smoke test');
        $password = bin2hex(random_bytes(16));
        [$user, $secret, $project, $detailPath] = $this->seedFileDatabase($password);
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

            $this->navigate($baseUrl.'/human-requests');
            $this->waitForSourceContaining('Attention-Inbox');
            $this->waitForSourceContaining('AI6-018-SMK');
            $this->assertNoHorizontalScrolling('Attention-Inbox');

            $this->navigate($baseUrl.$detailPath);
            $this->waitForSourceContaining('Eine Entscheidung wird benötigt.');
            $this->waitForSourceContaining('name="chosen_effect"');
            $this->assertNoHorizontalScrolling('Human-Request-Detail');
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

    /** @return array{User, string, Project, string} */
    private function seedFileDatabase(string $password): array
    {
        $this->initializeBrowserSmokeDatabase('ai6-hr-browser-smoke');
        Mail::fake();

        $opened = $this->openedHumanRequest('AI6-018-SMK');
        $opened['operator']->forceFill([
            'email' => 'hr-smoke@example.test',
            'password' => $password,
        ])->save();
        $secret = $this->createConfirmedTotp($opened['operator']);

        return [
            $opened['operator']->fresh(),
            $secret,
            $opened['project'],
            '/projects/'.$opened['project']->getKey().'/human-requests/'.$opened['request']->id,
        ];
    }
}
