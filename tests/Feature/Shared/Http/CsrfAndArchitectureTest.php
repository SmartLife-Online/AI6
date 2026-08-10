<?php

namespace Tests\Feature\Shared\Http;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Http\HttpSecurityConfiguration;
use App\AI6\Shared\Http\HttpSecurityConfigurationFactory;
use App\AI6\Shared\Security\SecurityMeasure;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Feature\Auth\AuthFeatureTestCase;

final class CsrfAndArchitectureTest extends AuthFeatureTestCase
{
    public function test_login_logout_and_administrative_mutation_reject_missing_and_foreign_csrf_tokens(): void
    {
        $this->app->instance('env', 'production');

        foreach ([
            ['POST', '/login'],
            ['POST', '/logout'],
            ['POST', '/admin/users'],
            ['POST', '/projects/1/deploy-key'],
            ['POST', '/projects/1/managed-clone'],
            ['POST', '/projects/1/managed-fetch'],
            ['POST', '/projects/1/control-branch'],
            ['POST', '/projects/1/operations/00000000-0000-0000-0000-000000000000/recovery'],
        ] as [$method, $path]) {
            $actualToken = bin2hex(random_bytes(20));
            $this->withSession(['_token' => $actualToken])
                ->call($method, $path)
                ->assertStatus(419);

            $this->withSession(['_token' => $actualToken])
                ->call($method, $path, ['_token' => 'foreign-'.$actualToken])
                ->assertStatus(419);
        }
    }

    public function test_csrf_middleware_is_present_without_exclusions_or_configuration_switches(): void
    {
        $groups = $this->app->make(Kernel::class)->getMiddlewareGroups();
        self::assertContains(PreventRequestForgery::class, $groups['web'] ?? []);

        $canonicalCalls = 0;

        foreach ($this->securityBoundaryPhpFiles() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $count = preg_match_all(
                '/->preventRequestForgery\s*\((?<arguments>.*?)\)/s',
                $source,
                $calls,
                PREG_SET_ORDER,
            );
            self::assertNotFalse($count);

            foreach ($calls as $call) {
                $canonicalCalls++;
                self::assertSame('', trim($call['arguments']), $path);
            }

            $compactSource = preg_replace('/\s+/', '', $source);
            self::assertIsString($compactSource);
            self::assertStringNotContainsString('->validateCsrfTokens(', $compactSource, $path);
            self::assertStringNotContainsString('PreventRequestForgery::except', $source, $path);
        }

        self::assertSame(1, $canonicalCalls);
    }

    public function test_invalid_hardening_configuration_fails_with_the_key_but_not_the_value(): void
    {
        $invalidValue = 'attacker host value';
        config(['ai6.http_hardening.trusted_hosts' => $invalidValue]);
        $this->app->forgetInstance(HttpSecurityConfiguration::class);

        try {
            $this->app->make(HttpSecurityConfiguration::class);
            self::fail('Invalid HTTP hardening configuration must fail during resolution.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('AI6_HTTP_TRUSTED_HOSTS', $exception->getMessage());
            self::assertStringNotContainsString($invalidValue, $exception->getMessage());
        }
    }

    public function test_invalid_hardening_configuration_still_returns_a_generic_http_error(): void
    {
        $invalidValue = 'attacker host value';
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
try {
    $response = $kernel->handle(Illuminate\Http\Request::create('/login'));
    fwrite(STDOUT, $response->getStatusCode()."\n".(string) $response->getContent());
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
PHP,
            ],
            base_path(),
            [
                'APP_DEBUG' => 'true',
                'APP_ENV' => 'testing',
                'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'AI6_HTTP_TRUSTED_HOSTS' => $invalidValue,
                'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE' => 'false',
                'AI6_SECURITY_PROFILE' => 'strict',
                'LOG_CHANNEL' => 'stderr',
            ],
        );
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame("500\nInterner Konfigurationsfehler.", trim($process->getOutput()));
        self::assertStringNotContainsString(
            $invalidValue,
            $process->getOutput().$process->getErrorOutput(),
        );
    }

    #[DataProvider('invalidTrustedHostsProvider')]
    public function test_host_validation_has_no_empty_or_wildcard_disable_path(string $invalidValue): void
    {
        $configuration = config('ai6.http_hardening');
        self::assertIsArray($configuration);
        $configuration['trusted_hosts'] = $invalidValue;

        $result = (new HttpSecurityConfigurationFactory)->inspect($configuration);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertStringContainsString('AI6_HTTP_TRUSTED_HOSTS', $result->message);

        if ($invalidValue !== '') {
            self::assertStringNotContainsString($invalidValue, $result->message);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTrustedHostsProvider(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'empty value' => [''];
        yield 'empty list entry' => ['localhost,,example.test'];
    }

    #[DataProvider('invalidTrustedProxyRangeProvider')]
    public function test_invalid_proxy_ranges_are_rejected_without_exposing_the_value(string $invalidValue): void
    {
        $configuration = config('ai6.http_hardening');
        self::assertIsArray($configuration);
        $configuration['trusted_proxies'] = $invalidValue;

        $result = (new HttpSecurityConfigurationFactory)->inspect($configuration);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertStringContainsString('AI6_HTTP_TRUSTED_PROXIES', $result->message);
        self::assertStringNotContainsString($invalidValue, $result->message);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTrustedProxyRangeProvider(): iterable
    {
        yield 'IPv4 canonical' => ['0.0.0.0/0'];
        yield 'IPv4 non-canonical address' => ['203.0.113.12/0'];
        yield 'IPv6 canonical' => ['::/0'];
        yield 'IPv6 non-canonical address' => ['2001:db8::1/0'];
        yield 'IPv4 multiple separators' => ['1.2.3.4/24/8'];
        yield 'IPv6 multiple separators' => ['2001:db8::1/64/8'];
    }

    public function test_http_hardening_has_no_second_security_measure_or_profile_based_decision(): void
    {
        self::assertCount(7, SecurityMeasure::cases());

        $enforcement = file_get_contents(app_path('AI6/Shared/Http/EnforceHttpsOrPrivateAccess.php'));
        self::assertIsString($enforcement);
        self::assertStringContainsString(
            'SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS',
            $enforcement,
        );
        self::assertStringNotContainsString('->profile', $enforcement);
        self::assertStringNotContainsString('getenv(', $enforcement);
        self::assertStringNotContainsString("config('ai6.security", $enforcement);

        $session = file_get_contents(config_path('session.php'));
        self::assertIsString($session);
        self::assertStringNotContainsString('SESSION_SECURE_COOKIE', $session);
        self::assertStringNotContainsString('SESSION_HTTP_ONLY', $session);
        self::assertStringNotContainsString('SESSION_SAME_SITE', $session);

        $configuration = config('ai6.http_hardening');
        self::assertIsArray($configuration);
        $configuration['csp_directives']['script-src'] = "'self' 'unsafe-inline'";
        $result = (new HttpSecurityConfigurationFactory)->inspect($configuration);
        self::assertNotInstanceOf(HttpSecurityConfiguration::class, $result);
        self::assertStringContainsString('ai6.http_hardening.csp_directives.script-src', $result->message);
    }

    public function test_fixed_http_hardening_configuration_violations_name_their_actual_config_paths(): void
    {
        $configuration = config('ai6.http_hardening');
        self::assertIsArray($configuration);

        $invalidAssetPath = $configuration;
        $invalidAssetPath['script_asset_path'] = 'assets/';
        $assetPathResult = (new HttpSecurityConfigurationFactory)->inspect($invalidAssetPath);
        self::assertInstanceOf(ConfigurationViolation::class, $assetPathResult);
        self::assertStringContainsString('ai6.http_hardening.script_asset_path', $assetPathResult->message);
        self::assertStringNotContainsString('AI6_HTTP_SCRIPT_ASSET_PATH', $assetPathResult->message);

        $invalidDirectiveSet = $configuration;
        $invalidDirectiveSet['csp_directives'] = [];
        $directiveSetResult = (new HttpSecurityConfigurationFactory)->inspect($invalidDirectiveSet);
        self::assertInstanceOf(ConfigurationViolation::class, $directiveSetResult);
        self::assertStringContainsString('ai6.http_hardening.csp_directives', $directiveSetResult->message);
        self::assertStringNotContainsString('AI6_HTTP_CSP_DIRECTIVES', $directiveSetResult->message);

        $invalidDirective = $configuration;
        $invalidDirective['csp_directives']['script-src'] = "'self' 'unsafe-inline'";
        $directiveResult = (new HttpSecurityConfigurationFactory)->inspect($invalidDirective);
        self::assertInstanceOf(ConfigurationViolation::class, $directiveResult);
        self::assertStringContainsString('ai6.http_hardening.csp_directives.script-src', $directiveResult->message);
        self::assertStringNotContainsString('AI6_HTTP_CSP_DIRECTIVES', $directiveResult->message);
    }

    /** @return list<string> */
    private function securityBoundaryPhpFiles(): array
    {
        $files = [];
        foreach ([app_path(), base_path('bootstrap'), base_path('routes')] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
