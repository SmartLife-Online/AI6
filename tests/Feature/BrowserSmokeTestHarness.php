<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Shared file-database, PHP server and plain-WebDriver harness for browser smokes. */
trait BrowserSmokeTestHarness
{
    private const WEB_DRIVER_ELEMENT_KEY = 'element-6066-11e4-a52e-4f735466cecf';

    private ?Process $browserSmokeServer = null;

    private ?Process $browserSmokeChromedriver = null;

    private ?string $browserSmokeDatabasePath = null;

    private string $browserSmokeDriverBase = '';

    private string $browserSmokeSessionId = '';

    protected function requireBrowserSmokeChromedriver(string $testName): string
    {
        $binary = getenv('AI6_BROWSER_SMOKE_CHROMEDRIVER_BINARY');

        if (getenv('AI6_BROWSER_SMOKE') !== '1' || ! is_string($binary) || ! is_file($binary)) {
            self::markTestSkipped(
                $testName.' requires AI6_BROWSER_SMOKE=1 and an existing '
                .'AI6_BROWSER_SMOKE_CHROMEDRIVER_BINARY.',
            );
        }

        return $binary;
    }

    protected function initializeBrowserSmokeDatabase(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8)).'.sqlite';
        self::assertNotFalse(touch($path));
        $this->browserSmokeDatabasePath = $path;
        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());

        return $path;
    }

    protected function startApplicationServer(int $port): void
    {
        $key = config('app.key');
        self::assertIsString($key);
        $this->browserSmokeServer = new Process(
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
                'DB_DATABASE' => (string) $this->browserSmokeDatabasePath,
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
        $this->browserSmokeServer->setTimeout(null);
        $this->browserSmokeServer->start();
        $this->waitFor(function () use ($port): bool {
            $health = $this->httpRequest('GET', 'http://127.0.0.1:'.$port.'/health');

            return is_string($health) && str_contains($health, '"ok"');
        }, 'The smoke application server did not become healthy.');
    }

    protected function startChromedriver(string $binary, int $port): void
    {
        $this->browserSmokeChromedriver = new Process([$binary, '--port='.$port]);
        $this->browserSmokeChromedriver->setTimeout(null);
        $this->browserSmokeChromedriver->start();
        $this->browserSmokeDriverBase = 'http://127.0.0.1:'.$port;
        $this->waitFor(function (): bool {
            $status = $this->webDriverHttpRequest('GET', '/status');

            return is_string($status) && str_contains($status, '"ready":true');
        }, 'The chromedriver endpoint did not become ready.');
    }

    protected function createBrowserSession(): void
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
        $this->browserSmokeSessionId = $sessionId;
    }

    protected function setViewport(int $width, int $height, bool $mobile): void
    {
        $response = $this->driverRequest(
            'POST',
            '/session/'.$this->browserSmokeSessionId.'/goog/cdp/execute',
            [
                'cmd' => 'Emulation.setDeviceMetricsOverride',
                'params' => [
                    'width' => $width,
                    'height' => $height,
                    'deviceScaleFactor' => $mobile ? 3 : 1,
                    'mobile' => $mobile,
                ],
            ],
        );
        self::assertNull($response['value']['error'] ?? null, 'The chromedriver CDP viewport command must succeed.');
        $this->driverRequest('POST', '/session/'.$this->browserSmokeSessionId.'/window/rect', [
            'x' => 0,
            'y' => 0,
            'width' => $width,
            'height' => $height,
        ]);

        $viewportWidth = $this->execute($mobile ? 'return window.screen.width;' : 'return window.innerWidth;');
        $viewportHeight = $this->execute($mobile ? 'return window.screen.height;' : 'return window.innerHeight;');
        self::assertIsNumeric($viewportWidth);
        self::assertIsNumeric($viewportHeight);
        self::assertSame(
            $width,
            (int) $viewportWidth,
            sprintf('The viewport width must become %d after setViewport().', $width),
        );
        self::assertSame(
            $height,
            (int) $viewportHeight,
            sprintf('The viewport height must become %d after setViewport().', $height),
        );
    }

    protected function assertNoHorizontalScrolling(
        string $viewName,
        int $width = 375,
        int $height = 812,
    ): void {
        $viewportWidth = $this->execute('return window.visualViewport?.width ?? window.innerWidth;');
        self::assertIsNumeric($viewportWidth);
        self::assertSame(
            $width,
            (int) $viewportWidth,
            sprintf('%s must render in the requested %d-pixel viewport.', $viewName, $width),
        );

        $overflow = $this->execute(
            'return Math.max('
            .'document.documentElement.scrollWidth - document.documentElement.clientWidth,'
            .'document.body.scrollWidth - document.body.clientWidth);',
        );
        self::assertIsNumeric($overflow);
        self::assertLessThanOrEqual(
            0,
            (int) $overflow,
            sprintf('%s must not scroll horizontally in the %dx%d viewport.', $viewName, $width, $height),
        );
    }

    protected function assertConsoleFreeOfPolicyViolations(): void
    {
        $log = $this->driverRequest(
            'POST',
            '/session/'.$this->browserSmokeSessionId.'/log',
            ['type' => 'browser'],
        );
        $entries = $log['value'] ?? [];
        self::assertIsArray($entries);
        $violations = [];

        foreach ($entries as $entry) {
            $message = is_array($entry) && is_string($entry['message'] ?? null) ? $entry['message'] : '';

            if (preg_match('/Content Security Policy|unsafe-eval|Refused to/i', $message) === 1) {
                $violations[] = $message;
            }
        }

        self::assertSame([], $violations, 'The browser console reported CSP violations.');
    }

    protected function navigate(string $url): void
    {
        $this->driverRequest('POST', '/session/'.$this->browserSmokeSessionId.'/url', ['url' => $url]);
    }

    protected function type(string $css, string $text): void
    {
        $element = $this->findElement($css);
        $this->driverRequest(
            'POST',
            '/session/'.$this->browserSmokeSessionId.'/element/'.$element.'/value',
            ['text' => $text],
        );
    }

    protected function click(string $css): void
    {
        $element = $this->findElement($css);
        $this->driverRequest(
            'POST',
            '/session/'.$this->browserSmokeSessionId.'/element/'.$element.'/click',
            new \stdClass,
        );
    }

    protected function execute(string $script): mixed
    {
        $response = $this->driverRequest(
            'POST',
            '/session/'.$this->browserSmokeSessionId.'/execute/sync',
            ['script' => $script, 'args' => []],
        );

        return $response['value'] ?? null;
    }

    protected function pageSource(): string
    {
        $response = $this->driverRequest('GET', '/session/'.$this->browserSmokeSessionId.'/source');
        $source = $response['value'] ?? null;
        self::assertIsString($source);

        return $source;
    }

    protected function waitForUrlContaining(string $needle): void
    {
        $this->waitFor(function () use ($needle): bool {
            $response = $this->driverRequest('GET', '/session/'.$this->browserSmokeSessionId.'/url');
            $url = $response['value'] ?? null;

            return is_string($url) && str_contains($url, $needle);
        }, sprintf('The browser never reached a URL containing "%s".', $needle));
    }

    protected function waitForSourceContaining(string $needle): void
    {
        $this->waitFor(
            fn (): bool => str_contains($this->pageSource(), $needle),
            sprintf('The page source never contained "%s".', $needle),
        );
    }

    protected function waitForSourceMissing(string $needle): void
    {
        $this->waitFor(
            fn (): bool => ! str_contains($this->pageSource(), $needle),
            sprintf('The page source kept containing "%s".', $needle),
        );
    }

    protected function waitFor(callable $condition, string $failure, float $seconds = 15.0): void
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

    protected function freePort(): int
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

    protected function tearDownBrowserSmokeHarness(): void
    {
        $this->shutdownBrowserStack();

        if ($this->browserSmokeDatabasePath !== null) {
            DB::disconnect('sqlite');
            DB::purge('sqlite');
            @unlink($this->browserSmokeDatabasePath);
            $this->browserSmokeDatabasePath = null;
        }
    }

    private function findElement(string $css): string
    {
        $found = null;
        $this->waitFor(function () use ($css, &$found): bool {
            $response = $this->driverRequest(
                'POST',
                '/session/'.$this->browserSmokeSessionId.'/element',
                ['using' => 'css selector', 'value' => $css],
                allowError: true,
            );
            $element = $response['value'][self::WEB_DRIVER_ELEMENT_KEY] ?? null;

            if (is_string($element)) {
                $found = $element;

                return true;
            }

            return false;
        }, sprintf('The element "%s" did not appear.', $css));
        self::assertIsString($found);

        return $found;
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
    private function webDriverHttpRequest(
        string $method,
        string $path,
        array|\stdClass|null $body = null,
    ): string|false {
        $url = parse_url($this->browserSmokeDriverBase);
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

        if (! is_string($statusLine) || preg_match('/^HTTP\/1\.[01] [1-5][0-9]{2}/', $statusLine) !== 1) {
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
    private function httpRequest(
        string $method,
        string $url,
        array|\stdClass|null $body = null,
    ): string|false {
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

    private function shutdownBrowserStack(): void
    {
        if ($this->browserSmokeSessionId !== '' && $this->browserSmokeDriverBase !== '') {
            try {
                $this->driverRequest(
                    'DELETE',
                    '/session/'.$this->browserSmokeSessionId,
                    allowError: true,
                );
            } catch (RuntimeException) {
                // The browser session is gone; process shutdown follows anyway.
            }
            $this->browserSmokeSessionId = '';
        }

        foreach ([$this->browserSmokeChromedriver, $this->browserSmokeServer] as $process) {
            if ($process instanceof Process && $process->isRunning()) {
                $process->stop(5);
            }
        }

        $this->browserSmokeChromedriver = null;
        $this->browserSmokeServer = null;
    }
}
