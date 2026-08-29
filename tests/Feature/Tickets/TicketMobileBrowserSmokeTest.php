<?php

namespace Tests\Feature\Tickets;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use Tests\Feature\BrowserSmokeTestHarness;

/**
 * TC-03: real mobile-viewport smoke proof behind an explicit flag. The test
 * drives a headless Chrome through a chromedriver binary over the plain
 * WebDriver HTTP protocol, so no additional Composer dependency exists.
 * Without the flag it skips and is never reported as executed.
 */
final class TicketMobileBrowserSmokeTest extends TicketUiTestCase
{
    use BrowserSmokeTestHarness;

    public function test_mobile_viewport_shows_core_information_without_horizontal_scrolling(): void
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

            $this->navigate($baseUrl.'/projects/'.$project->getKey().'/tickets');
            $this->waitForSourceContaining('Fertiges Ziel ohne Scrollen.');
            $this->assertNonSecureProgressGuardRemovedOnlyTheKnownStyle();
            self::assertStringContainsString('Offenes Ziel ohne Scrollen.', $this->pageSource());
            $this->assertNoHorizontalScrolling('Ticketliste');

            $this->click('#ticket-status-filter');
            $this->click('#ticket-status-filter option[value="done"]');
            $this->waitForSourceMissing('Offenes Ziel ohne Scrollen.');
            self::assertStringContainsString('Fertiges Ziel ohne Scrollen.', $this->pageSource());

            $this->click('#ticket-status-filter');
            $this->click('#ticket-status-filter option[value=""]');
            $this->waitForSourceContaining('Offenes Ziel ohne Scrollen.');

            $this->click('#ticket-state-filter');
            $this->click('#ticket-state-filter option[value="invalid"]');
            $this->waitForSourceContaining('Ungültiges Ziel ohne Scrollen.');
            $this->waitForSourceMissing('Fertiges Ziel ohne Scrollen.');
            self::assertStringNotContainsString('Offenes Ziel ohne Scrollen.', $this->pageSource());

            $this->click('#ticket-state-filter');
            $this->click('#ticket-state-filter option[value=""]');
            $this->waitForSourceContaining('Fertiges Ziel ohne Scrollen.');

            $this->navigate($baseUrl.$detailPath);
            $this->waitForSourceContaining('Ungültiges Ziel ohne Scrollen.');
            $this->assertNoHorizontalScrolling('Ticketdetail');
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
        $this->initializeBrowserSmokeDatabase('ai6-browser-smoke');

        $user = $this->createUser(['email' => 'smoke@example.test', 'password' => $password]);
        $secret = $this->createConfirmedTotp($user);
        $project = $this->provisionedProject($user);
        $this->publishReadModel(
            $user,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'done', '[]', 'Fertiges Ziel ohne Scrollen.'),
        );
        $this->publishReadModel(
            $user,
            $project,
            'tickets/T2.md',
            $this->validTicketMarkdown('T2', 'todo', '[T1]', 'Offenes Ziel ohne Scrollen.'),
        );
        $detail = $this->publishReadModel(
            $user,
            $project,
            'tickets/B1.md',
            $this->validTicketMarkdown('B1', 'wip', '[]', 'Ungültiges Ziel ohne Scrollen.'),
        );

        return [
            $user,
            $secret,
            $project,
            '/projects/'.$project->getKey().'/tickets/'.$detail->getKey(),
        ];
    }

    private function assertNonSecureProgressGuardRemovedOnlyTheKnownStyle(): void
    {
        self::assertFalse(
            $this->execute('return window.isSecureContext;'),
            'The smoke host must exercise the browser path without WebCrypto subtle support.',
        );
        self::assertTrue($this->execute('return globalThis.crypto?.subtle === undefined;'));
        self::assertSame(
            0,
            $this->execute('return document.head.querySelectorAll("style").length;'),
            'The exact Livewire progress style must be stopped before CSP sees an inline node.',
        );
        self::assertFalse(
            $this->execute('return Object.prototype.hasOwnProperty.call(document.head, "appendChild");'),
            'The one-shot guard must restore the native DOM method after Livewire initializes.',
        );
    }
}
