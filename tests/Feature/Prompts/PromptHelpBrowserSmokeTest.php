<?php

namespace Tests\Feature\Prompts;

use App\AI6\Prompts\PromptHelpGuestAccess;
use Tests\Feature\Auth\AuthFeatureTestCase;
use Tests\Feature\BrowserSmokeTestHarness;

/**
 * TC-08: explicit guest browser proof, with the guest access switch set for
 * the smoke server, for clipboard success, denied clipboard access, full
 * fallback selection and a 375-pixel viewport. Without the flag the test skips
 * and is never reported as executed.
 */
final class PromptHelpBrowserSmokeTest extends AuthFeatureTestCase
{
    use BrowserSmokeTestHarness;

    public function test_clipboard_success_fallback_selection_and_mobile_width(): void
    {
        $chromedriverBinary = $this->requireBrowserSmokeChromedriver('The prompt-help browser smoke test');
        $this->initializeBrowserSmokeDatabase('ai6-prompt-help-smoke');
        $appPort = $this->freePort();
        $driverPort = $this->freePort();
        $baseUrl = 'http://ai6-smoke.test:'.$appPort;

        try {
            $this->startApplicationServer($appPort, [PromptHelpGuestAccess::ENVIRONMENT_KEY => 'true']);
            $this->startChromedriver($chromedriverBinary, $driverPort);
            $this->createBrowserSession();

            $this->navigate($baseUrl.'/login');
            $this->click('nav a[href$="/prompts/help"]');
            $this->waitForSourceContaining('Eigenen Reviewbefund beheben und re-reviewen');
            $this->assertNoHorizontalScrolling('Prompt-Hilfe mobil', 375, 812);

            $this->execute(
                'Object.defineProperty(navigator, "clipboard", {'
                .' configurable: true,'
                .' value: { writeText: function (text) {'
                .'  window.__ai6Copied = text;'
                .'  return Promise.resolve();'
                .' } }'
                .' });',
            );
            $this->click('[data-prompt-copy="static-own-preview"]');
            $this->waitFor(function (): bool {
                return $this->execute('return window.__ai6Copied === document.getElementById("static-own-preview").value;') === true;
            }, 'The clipboard write did not receive the visible preview bytes.');
            $this->waitForSourceContaining('In die Zwischenablage kopiert.');

            $this->execute(
                'window.__ai6Copied = null;'
                .' Object.defineProperty(navigator, "clipboard", {'
                .'  configurable: true,'
                .'  value: { writeText: function () { return Promise.reject(new Error("denied")); } }'
                .' });',
            );
            $this->click('[data-prompt-copy="static-foreign-preview"]');
            $this->waitFor(function (): bool {
                $status = $this->execute('return document.getElementById("static-foreign-preview-status").textContent;');

                return is_string($status)
                    && str_contains($status, 'Zwischenablage nicht verfügbar')
                    && ! str_contains($status, 'In die Zwischenablage kopiert.');
            }, 'The denied clipboard path must show the fallback without a success status.');
            $selected = $this->execute(
                'var field = document.getElementById("static-foreign-preview");'
                .' return document.activeElement === field'
                .' && field.selectionStart === 0'
                .' && field.selectionEnd === field.value.length;',
            );
            self::assertTrue($selected, 'The fallback must select the complete preview.');
            self::assertNull($this->execute('return window.__ai6Copied;'));

            $this->type('#review-answer', "Review\n### Fix-Liste\n- nur die Liste");
            $this->click('form.ai6-prompt-form button[type=submit]');
            $this->waitForSourceContaining('nur die Liste');
            $this->waitFor(function (): bool {
                return $this->execute('return document.getElementById("review-answer").value === "";') === true;
            }, 'The review-answer field must be empty after processing.');

            $this->execute(
                'Object.defineProperty(navigator, "clipboard", {'
                .' configurable: true,'
                .' value: { writeText: function (text) {'
                .'  window.__ai6Copied = text; return Promise.resolve();'
                .' } } });',
            );
            $this->click('[data-prompt-copy="dynamic-preview"]');
            $this->waitFor(function (): bool {
                return $this->execute('return window.__ai6Copied === document.getElementById("dynamic-preview").value'
                    .' && window.__ai6Copied.includes("nur die Liste");') === true;
            }, 'The dynamic clipboard bytes must equal the complete visible preview.');
            $this->waitFor(function (): bool {
                return $this->execute('return document.getElementById("dynamic-preview-status").textContent;')
                    === 'In die Zwischenablage kopiert.';
            }, 'The dynamic clipboard success was not confirmed.');

            $this->setViewport(1280, 800, false);
            $this->assertNoHorizontalScrolling('Prompt-Hilfe Laptop', 1280, 800);
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
}
