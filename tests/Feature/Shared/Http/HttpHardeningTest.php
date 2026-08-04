<?php

namespace Tests\Feature\Shared\Http;

use App\AI6\Shared\Http\HttpSecurityConfiguration;
use App\AI6\Shared\Http\HttpSecurityConfigurationFactory;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use DOMDocument;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Auth\AuthFeatureTestCase;

final class HttpHardeningTest extends AuthFeatureTestCase
{
    public function test_login_ignores_remember_input_and_uses_a_non_persistent_session_cookie(): void
    {
        $password = bin2hex(random_bytes(16));
        $this->createUser([
            'email' => 'remember@example.test',
            'password' => $password,
        ]);

        $response = $this->post('/login', [
            'email' => 'remember@example.test',
            'password' => $password,
            'remember' => '1',
        ]);

        $response->assertRedirect(route('auth.enrollment.totp.show'));
        $sessionCookie = $this->sessionCookie($response);
        self::assertSame(0, $sessionCookie->getExpiresTime());

        foreach ($response->headers->getCookies() as $cookie) {
            self::assertFalse(str_starts_with($cookie->getName(), 'remember_'));
        }
    }

    public function test_cookie_attributes_follow_only_the_https_measure_and_request_scheme(): void
    {
        $this->useSecurityPolicy(SecurityProfile::STRICT, httpsMeasureEnabled: true);
        $strictCookie = $this->sessionCookie($this->htmlRequest('127.0.0.1'));
        $this->assertFixedCookieAttributes($strictCookie);
        self::assertTrue($strictCookie->isSecure());

        $this->useSecurityPolicy(SecurityProfile::DEVELOPMENT, httpsMeasureEnabled: false);
        $developmentHttpCookie = $this->sessionCookie($this->htmlRequest('127.0.0.1'));
        $this->assertFixedCookieAttributes($developmentHttpCookie);
        self::assertFalse($developmentHttpCookie->isSecure());
        $developmentHttpsCookie = $this->sessionCookie($this->htmlRequest('203.0.113.10', true));
        $this->assertFixedCookieAttributes($developmentHttpsCookie);
        self::assertTrue($developmentHttpsCookie->isSecure());

        $this->useSecurityPolicy(
            SecurityProfile::CUSTOM,
            httpsMeasureEnabled: true,
            sandboxMeasureEnabled: false,
        );
        $customOtherReductionCookie = $this->sessionCookie($this->htmlRequest('127.0.0.1'));
        $this->assertFixedCookieAttributes($customOtherReductionCookie);
        self::assertTrue($customOtherReductionCookie->isSecure());

        $this->useSecurityPolicy(SecurityProfile::CUSTOM, httpsMeasureEnabled: false);
        $customHttpCookie = $this->sessionCookie($this->htmlRequest('127.0.0.1'));
        $this->assertFixedCookieAttributes($customHttpCookie);
        self::assertFalse($customHttpCookie->isSecure());
    }

    public function test_profile_matrix_rejects_non_private_plain_http_without_redirecting(): void
    {
        $this->useSecurityPolicy(SecurityProfile::STRICT, httpsMeasureEnabled: true);
        $this->htmlRequest('203.0.113.10', true)->assertOk();
        $this->assertPlainHttpRejected('203.0.113.10');

        $this->useSecurityPolicy(SecurityProfile::DEVELOPMENT, httpsMeasureEnabled: false);
        $this->htmlRequest('127.0.0.1')->assertOk();
        $this->assertPlainHttpRejected('203.0.113.10');

        $this->useSecurityPolicy(
            SecurityProfile::CUSTOM,
            httpsMeasureEnabled: true,
            sandboxMeasureEnabled: false,
        );
        $this->htmlRequest('127.0.0.1')->assertOk();
        $this->assertPlainHttpRejected('203.0.113.10');

        $this->useSecurityPolicy(SecurityProfile::CUSTOM, httpsMeasureEnabled: false);
        $this->htmlRequest('127.0.0.1')->assertOk();
        $this->assertPlainHttpRejected('203.0.113.10');
    }

    public function test_private_access_is_limited_to_direct_or_proxy_resolved_loopback(): void
    {
        $this->useSecurityPolicy(SecurityProfile::DEVELOPMENT, httpsMeasureEnabled: false);

        foreach (['127.0.0.1', '127.42.10.7', '::1'] as $loopback) {
            $this->htmlRequest($loopback)->assertOk();
        }

        foreach (['10.0.0.1', '172.16.0.1', '192.168.0.1', 'fc00::1'] as $nonLoopback) {
            $this->assertPlainHttpRejected($nonLoopback);
        }

        $this->useHttpConfiguration('10.0.0.2');
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
        ])->assertOk();
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '::1',
        ])->assertOk();
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.168.0.1',
        ])->assertStatus(400);

        $this->useHttpConfiguration('127.0.0.1');
        $this->requestWithServer([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.0.1',
        ])->assertStatus(400);
        $this->requestWithServer([
            'REMOTE_ADDR' => '127.0.0.1',
        ])->assertOk();
    }

    public function test_host_and_forwarding_values_are_accepted_only_from_configuration(): void
    {
        $this->htmlRequest('127.0.0.1', server: ['HTTP_HOST' => 'evil.example'])->assertStatus(400);
        $this->htmlRequest('127.0.0.1', server: ['HTTP_HOST' => 'localhost'])->assertOk();

        $this->requestWithServer([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertStatus(400);

        $this->useHttpConfiguration('10.0.0.2');
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'localhost',
        ])->assertOk();
        $this->requestWithServer([
            'HTTP_HOST' => 'evil.example',
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'localhost',
        ])->assertStatus(400);
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
        ])->assertStatus(400);
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_FORWARDED' => 'for=127.0.0.1;proto=https',
        ])->assertStatus(400);
        $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_SERVER' => 'localhost',
        ])->assertStatus(400);
    }

    public function test_only_a_configured_proxy_can_resolve_the_https_scheme(): void
    {
        $this->useSecurityPolicy(SecurityProfile::STRICT, httpsMeasureEnabled: true);

        $this->requestWithServer([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->assertStatus(400);

        $this->useHttpConfiguration('10.0.0.2');
        $response = $this->requestWithServer([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $response->assertOk();
        self::assertTrue($this->sessionCookie($response)->isSecure());
    }

    public function test_every_html_response_receives_the_fixed_script_policy(): void
    {
        $expected = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; img-src 'self'; "
            ."font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; "
            ."object-src 'none'; frame-ancestors 'none';";

        foreach (['/', '/login', '/missing-page'] as $path) {
            $response = $this->get($path);
            $response->assertHeader('Content-Security-Policy', $expected);
            $policy = (string) $response->headers->get('Content-Security-Policy');
            self::assertStringNotContainsString("'unsafe-inline'", $policy);
            self::assertStringNotContainsString("'unsafe-eval'", $policy);
        }

        $this->get('/health')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_html_response_bodies_and_templates_obey_the_static_script_contract(): void
    {
        $user = $this->createUser();
        $project = $this->createProject();
        $this->addMembership($user, $project);

        $responses = [
            $this->get('/'),
            $this->get('/login'),
            $this->get('/missing-page'),
            $this->actingAs($user)->get('/projects'),
            $this->actingAs($user)->get('/projects/'.$project->getKey()),
        ];

        foreach ($responses as $response) {
            $contentType = strtolower((string) $response->headers->get('Content-Type'));

            if (str_contains($contentType, 'text/html')) {
                $this->assertHtmlScriptContract($response->getContent());
            }
        }

        foreach ($this->bladeViewFiles() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/\son[a-z][a-z0-9_-]*\s*=/i', $source, $path);

            $count = preg_match_all(
                '/<script\b(?<attributes>[^>]*)>(?<body>.*?)<\/script>/is',
                $source,
                $scripts,
                PREG_SET_ORDER,
            );
            self::assertNotFalse($count);

            foreach ($scripts as $script) {
                self::assertMatchesRegularExpression(
                    '/\bsrc\s*=\s*["\']\{\{\s*asset\(["\']assets\/[A-Za-z0-9._\/-]+["\']\)\s*\}\}["\']/i',
                    $script['attributes'],
                    $path,
                );
                self::assertSame('', trim($script['body']), $path);
            }
        }
    }

    public function test_html_script_contract_rejects_an_inline_script_counterexample(): void
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertHtmlScriptContract('<!doctype html><html><body><script>alert(1)</script></body></html>');
    }

    private function assertPlainHttpRejected(string $remoteAddress): void
    {
        $response = $this->htmlRequest($remoteAddress);
        $response->assertStatus(400);
        self::assertFalse($response->isRedirect());
    }

    private function assertFixedCookieAttributes(Cookie $cookie): void
    {
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
    }

    private function assertHtmlScriptContract(string $html): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            self::assertTrue($document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                self::assertFalse(
                    str_starts_with(strtolower($attribute->nodeName), 'on'),
                    'HTML responses must not contain event handler attributes.',
                );
            }
        }

        foreach ($document->getElementsByTagName('script') as $script) {
            self::assertTrue($script->hasAttribute('src'), 'Inline script elements are forbidden.');
            self::assertSame('', trim($script->textContent), 'Inline script content is forbidden.');

            $source = parse_url($script->getAttribute('src'));
            self::assertIsArray($source, 'Script sources must be valid URLs.');
            self::assertIsString($source['path'] ?? null);
            self::assertStringStartsWith('/assets/', $source['path']);

            if (array_key_exists('host', $source)) {
                self::assertSame('localhost', $source['host']);
            }
        }
    }

    /** @return list<string> */
    private function bladeViewFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @param TestResponse<Response> $response */
    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookieName = config('session.cookie');
        self::assertIsString($cookieName);
        $cookie = $response->getCookie($cookieName, false);
        self::assertInstanceOf(Cookie::class, $cookie);

        return $cookie;
    }

    /**
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function htmlRequest(
        string $remoteAddress,
        bool $https = false,
        array $server = [],
    ): TestResponse {
        return $this->requestWithServer(array_replace([
            'REMOTE_ADDR' => $remoteAddress,
            'HTTPS' => $https ? 'on' : 'off',
            'SERVER_PORT' => $https ? '443' : '80',
        ], $server));
    }

    /**
     * @param  array<string, string>  $server
     * @return TestResponse<Response>
     */
    private function requestWithServer(array $server): TestResponse
    {
        $server = array_replace([
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
        ], $server);
        $scheme = $server['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $server['HTTP_HOST'];

        return $this->withSession(['http-hardening-probe' => bin2hex(random_bytes(4))])
            ->call('GET', $scheme.'://'.$host.'/login', server: $server);
    }

    private function useHttpConfiguration(string $trustedProxies): void
    {
        $configuration = config('ai6.http_hardening');
        self::assertIsArray($configuration);
        $configuration['trusted_proxies'] = $trustedProxies;
        $parsed = (new HttpSecurityConfigurationFactory)->inspect($configuration);
        self::assertInstanceOf(HttpSecurityConfiguration::class, $parsed);
        $this->app->instance(HttpSecurityConfiguration::class, $parsed);
    }

    private function useSecurityPolicy(
        SecurityProfile $profile,
        bool $httpsMeasureEnabled,
        bool $sandboxMeasureEnabled = true,
    ): void {
        $measures = [];

        foreach (SecurityMeasure::cases() as $measure) {
            $measures[$measure->value] = true;
        }

        $measures[SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value] = $httpsMeasureEnabled;
        $measures[SecurityMeasure::REQUIRE_AGENT_SANDBOX->value] = $sandboxMeasureEnabled;
        $this->app->instance(SecurityPolicy::class, new SecurityPolicy(
            $profile,
            $measures,
            ! $httpsMeasureEnabled || ! $sandboxMeasureEnabled,
        ));
    }
}
