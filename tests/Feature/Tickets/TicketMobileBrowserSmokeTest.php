<?php

namespace Tests\Feature\Tickets;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * TC-03: real mobile-viewport smoke proof behind an explicit flag. The test
 * drives a headless Chrome through a chromedriver binary over the plain
 * WebDriver HTTP protocol, so no additional Composer dependency exists.
 * Without the flag it skips and is never reported as executed.
 */
final class TicketMobileBrowserSmokeTest extends TicketUiTestCase
{
    private const ELEMENT_KEY = 'element-6066-11e4-a52e-4f735466cecf';

    private ?Process $server = null;

    private ?Process $chromedriver = null;

    private ?string $databasePath = null;

    private string $driverBase = '';

    private string $sessionId = '';

    public function test_mobile_viewport_shows_core_information_without_horizontal_scrolling(): void
    {
        $chromedriverBinary = getenv('AI6_BROWSER_SMOKE_CHROMEDRIVER_BINARY');

        if (getenv('AI6_BROWSER_SMOKE') !== '1'
            || ! is_string($chromedriverBinary)
            || ! is_file($chromedriverBinary)) {
            self::markTestSkipped(
                'The mobile browser smoke test requires AI6_BROWSER_SMOKE=1 and an existing AI6_BROWSER_SMOKE_CHROMEDRIVER_BINARY.',
            );
        }

        $password = bin2hex(random_bytes(16));
        [$user, $secret, $project, $detailPath] = $this->seedFileDatabase($password);
        $appPort = $this->freePort();
        $driverPort = $this->freePort();
        $baseUrl = 'http://ai6-smoke.test:'.$appPort;

        try {
            $this->startApplicationServer($appPort);
            $this->startChromedriver($chromedriverBinary, $driverPort);
            $this->createBrowserSession($driverPort);

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
            $this->shutdownBrowserStack();
        }
    }

    protected function tearDown(): void
    {
        $this->shutdownBrowserStack();

        if ($this->databasePath !== null) {
            DB::disconnect('sqlite');
            DB::purge('sqlite');
            @unlink($this->databasePath);
            $this->databasePath = null;
        }

        parent::tearDown();
    }

    /** @return array{User, string, Project, string} */
    private function seedFileDatabase(string $password): array
    {
        $path = sys_get_temp_dir().'/ai6-browser-smoke-'.bin2hex(random_bytes(8)).'.sqlite';
        self::assertNotFalse(touch($path));
        $this->databasePath = $path;
        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());

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

    private function startApplicationServer(int $port): void
    {
        $key = config('app.key');
        self::assertIsString($key);
        // The framework router script serves existing public files (the CSS
        // asset and the passkey script) directly, exactly like production; it
        // resolves the public directory from the working directory.
        $this->server = new Process(
            [
                PHP_BINARY,
                '-S',
                '127.0.0.1:'.$port,
                '-t',
                public_path(),
                base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
            ],
            public_path(),
            [
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'false',
                'APP_KEY' => $key,
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => (string) $this->databasePath,
                'SESSION_DRIVER' => 'database',
                'QUEUE_CONNECTION' => 'database',
                'CACHE_STORE' => 'file',
                'LOG_CHANNEL' => 'stderr',
                'MAIL_MAILER' => 'array',
                'AI6_SECURITY_PROFILE' => 'custom',
                'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE' => 'true',
                'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS' => 'false',
                'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION' => 'false',
                'AI6_HTTP_TRUSTED_HOSTS' => 'localhost,127.0.0.1,::1,ai6-smoke.test',
            ],
        );
        $this->server->setTimeout(null);
        $this->server->start();
        $this->waitFor(function () use ($port): bool {
            $health = $this->httpRequest('GET', 'http://127.0.0.1:'.$port.'/health');

            return is_string($health) && str_contains($health, '"ok"');
        }, 'The smoke application server did not become healthy.');
    }

    private function startChromedriver(string $binary, int $port): void
    {
        $this->chromedriver = new Process([$binary, '--port='.$port]);
        $this->chromedriver->setTimeout(null);
        $this->chromedriver->start();
        $this->driverBase = 'http://127.0.0.1:'.$port;
        $this->waitFor(function (): bool {
            $status = $this->webDriverHttpRequest('GET', '/status');

            return is_string($status) && str_contains($status, '"ready":true');
        }, 'The chromedriver endpoint did not become ready.');
    }

    private function createBrowserSession(int $driverPort): void
    {
        $chromeOptions = [
            'args' => [
                '--headless=new',
                '--disable-gpu',
                '--window-size=375,812',
                '--remote-debugging-pipe',
                '--no-proxy-server',
                '--host-resolver-rules=MAP ai6-smoke.test 127.0.0.1',
            ],
            'mobileEmulation' => [
                'deviceMetrics' => ['width' => 375, 'height' => 812, 'pixelRatio' => 3],
            ],
        ];
        $chromeBinary = getenv('AI6_BROWSER_SMOKE_CHROME_BINARY');

        if (is_string($chromeBinary) && is_file($chromeBinary)) {
            $chromeOptions['binary'] = $chromeBinary;
        }

        $session = $this->driverRequest('POST', '/session', [
            'capabilities' => [
                'alwaysMatch' => [
                    'browserName' => 'chrome',
                    'goog:chromeOptions' => $chromeOptions,
                    'goog:loggingPrefs' => ['browser' => 'ALL'],
                ],
            ],
        ]);
        $sessionId = $session['value']['sessionId'] ?? null;
        self::assertIsString($sessionId);
        $this->sessionId = $sessionId;
    }

    private function assertNoHorizontalScrolling(string $viewName): void
    {
        $overflow = $this->execute(
            'return Math.max('
            .'document.documentElement.scrollWidth - document.documentElement.clientWidth,'
            .'document.body.scrollWidth - document.body.clientWidth);',
        );
        self::assertIsNumeric($overflow);
        self::assertLessThanOrEqual(
            0,
            (int) $overflow,
            sprintf('%s must not scroll horizontally in the 375x812 viewport.', $viewName),
        );
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

    private function assertConsoleFreeOfPolicyViolations(): void
    {
        $log = $this->driverRequest('POST', '/session/'.$this->sessionId.'/log', ['type' => 'browser']);
        $entries = $log['value'] ?? [];
        self::assertIsArray($entries);
        $violations = [];

        // Deliberately strict, without any console allowlist. The external AI6
        // guard may suppress only the exact hash-bound bytes of Livewire's
        // disabled progress-bar style before insertion; any changed injection,
        // unsafe-eval dependency or other CSP violation remains observable.
        foreach ($entries as $entry) {
            $message = is_array($entry) && is_string($entry['message'] ?? null) ? $entry['message'] : '';

            if (preg_match('/Content Security Policy|unsafe-eval|Refused to/i', $message) === 1) {
                $violations[] = $message;
            }
        }

        self::assertSame([], $violations, 'The browser console reported CSP violations.');
    }

    private function navigate(string $url): void
    {
        $this->driverRequest('POST', '/session/'.$this->sessionId.'/url', ['url' => $url]);
    }

    private function type(string $css, string $text): void
    {
        $element = $this->findElement($css);
        $this->driverRequest('POST', '/session/'.$this->sessionId.'/element/'.$element.'/value', ['text' => $text]);
    }

    private function click(string $css): void
    {
        $element = $this->findElement($css);
        $this->driverRequest('POST', '/session/'.$this->sessionId.'/element/'.$element.'/click', new \stdClass);
    }

    private function findElement(string $css): string
    {
        $found = null;
        $this->waitFor(function () use ($css, &$found): bool {
            $response = $this->driverRequest('POST', '/session/'.$this->sessionId.'/element', [
                'using' => 'css selector',
                'value' => $css,
            ], allowError: true);
            $element = $response['value'][self::ELEMENT_KEY] ?? null;

            if (is_string($element)) {
                $found = $element;

                return true;
            }

            return false;
        }, sprintf('The element "%s" did not appear.', $css));
        self::assertIsString($found);

        return $found;
    }

    private function execute(string $script): mixed
    {
        $response = $this->driverRequest('POST', '/session/'.$this->sessionId.'/execute/sync', [
            'script' => $script,
            'args' => [],
        ]);

        return $response['value'] ?? null;
    }

    private function pageSource(): string
    {
        $response = $this->driverRequest('GET', '/session/'.$this->sessionId.'/source');
        $source = $response['value'] ?? null;
        self::assertIsString($source);

        return $source;
    }

    private function waitForUrlContaining(string $needle): void
    {
        $this->waitFor(function () use ($needle): bool {
            $response = $this->driverRequest('GET', '/session/'.$this->sessionId.'/url');
            $url = $response['value'] ?? null;

            return is_string($url) && str_contains($url, $needle);
        }, sprintf('The browser never reached a URL containing "%s".', $needle));
    }

    private function waitForSourceContaining(string $needle): void
    {
        $this->waitFor(
            fn (): bool => str_contains($this->pageSource(), $needle),
            sprintf('The page source never contained "%s".', $needle),
        );
    }

    private function waitForSourceMissing(string $needle): void
    {
        $this->waitFor(
            fn (): bool => ! str_contains($this->pageSource(), $needle),
            sprintf('The page source kept containing "%s".', $needle),
        );
    }

    private function waitFor(callable $condition, string $failure, float $seconds = 15.0): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition() === true) {
                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException($failure);
    }

    /**
     * @param  array<string, mixed>|\stdClass|null  $body
     * @return array<string, mixed>
     */
    private function driverRequest(
        string $method,
        string $path,
        array|\stdClass|null $body = null,
        bool $allowError = false,
    ): array {
        $raw = $this->webDriverHttpRequest($method, $path, $body);

        if (! is_string($raw)) {
            if ($allowError) {
                return [];
            }

            throw new RuntimeException(sprintf('The WebDriver call %s %s failed.', $method, $path));
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>|\stdClass|null  $body */
    private function webDriverHttpRequest(string $method, string $path, array|\stdClass|null $body = null): string|false
    {
        $url = parse_url($this->driverBase);
        $host = $url['host'] ?? null;
        $port = $url['port'] ?? null;

        if (! is_string($host) || ! is_int($port)) {
            return false;
        }

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            30,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return false;
        }

        stream_set_timeout($socket, 30);
        $content = $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $request = $method.' '.$path." HTTP/1.1\r\n"
            .'Host: '.$host.':'.$port."\r\n"
            ."Accept: application/json\r\n"
            ."Connection: close\r\n"
            ."Content-Type: application/json\r\n"
            .'Content-Length: '.strlen($content)."\r\n\r\n"
            .$content;
        $written = 0;

        while ($written < strlen($request)) {
            $bytes = fwrite($socket, substr($request, $written));

            if ($bytes === false || $bytes === 0) {
                fclose($socket);

                return false;
            }

            $written += $bytes;
        }

        $statusLine = fgets($socket);

        if (! is_string($statusLine) || ! preg_match('/^HTTP\/1\.[01] [1-5][0-9]{2}/', $statusLine)) {
            fclose($socket);

            return false;
        }

        $contentLength = null;

        while (($header = fgets($socket)) !== false && $header !== "\r\n") {
            if (preg_match('/^Content-Length:\s*([0-9]+)\s*$/i', trim($header), $matches) === 1) {
                $contentLength = (int) $matches[1];
            }
        }

        if ($contentLength === null) {
            fclose($socket);

            return false;
        }

        $response = '';

        while (strlen($response) < $contentLength) {
            $chunk = fread($socket, $contentLength - strlen($response));

            if ($chunk === false || $chunk === '') {
                fclose($socket);

                return false;
            }

            $response .= $chunk;
        }

        fclose($socket);

        return $response;
    }

    /** @param  array<string, mixed>|\stdClass|null  $body */
    private function httpRequest(string $method, string $url, array|\stdClass|null $body = null): string|false
    {
        $options = [
            'http' => [
                'method' => $method,
                'timeout' => 30,
                'ignore_errors' => true,
                'header' => "Content-Type: application/json\r\n",
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        return @file_get_contents($url, false, stream_context_create($options));
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException('No free loopback port is available: '.$errorMessage);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;

        if ($port < 1) {
            throw new RuntimeException('The loopback port could not be determined.');
        }

        return $port;
    }

    private function shutdownBrowserStack(): void
    {
        if ($this->sessionId !== '' && $this->driverBase !== '') {
            try {
                $this->driverRequest('DELETE', '/session/'.$this->sessionId, null, allowError: true);
            } catch (RuntimeException) {
                // The browser session is gone; process shutdown follows anyway.
            }
            $this->sessionId = '';
        }

        foreach ([$this->chromedriver, $this->server] as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(5);
            }
        }

        $this->chromedriver = null;
        $this->server = null;
    }
}
