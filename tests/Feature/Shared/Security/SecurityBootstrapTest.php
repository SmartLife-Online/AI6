<?php

namespace Tests\Feature\Shared\Security;

use App\AI6\Shared\AI6ServiceProvider;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SecurityBootstrapTest extends TestCase
{
    public function test_invalid_boolean_fails_before_command_and_http_application_code_without_echoing_value(): void
    {
        $privateValue = 'yess-private-raw-value';
        $environment = $this->environment([
            'APP_DEBUG' => 'true',
            SecurityMeasure::REQUIRE_AGENT_SANDBOX->value => $privateValue,
        ]);
        $command = $this->process([PHP_BINARY, 'artisan', 'about'], $environment);
        $http = $this->httpProcess($environment);

        self::assertNotSame(0, $command->getExitCode());
        $commandOutput = $command->getOutput().$command->getErrorOutput();
        $compactCommandOutput = $this->withoutWhitespace($commandOutput);
        self::assertStringContainsString(SecurityMeasure::REQUIRE_AGENT_SANDBOX->value, $compactCommandOutput);
        self::assertStringContainsString('true,false,1,0,yes,no,on,off', $compactCommandOutput);
        self::assertStringNotContainsString($privateValue, $commandOutput);

        self::assertNotSame(0, $http->getExitCode());
        self::assertSame('Interner Konfigurationsfehler.', trim($http->getOutput()));
        self::assertStringNotContainsString(SecurityMeasure::REQUIRE_AGENT_SANDBOX->value, $http->getOutput());
        self::assertStringNotContainsString('true, false, 1, 0, yes, no, on, off', $http->getOutput());
        self::assertStringNotContainsString($privateValue, $http->getOutput().$http->getErrorOutput());
        self::assertStringContainsString(
            SecurityMeasure::REQUIRE_AGENT_SANDBOX->value,
            $this->withoutWhitespace($http->getErrorOutput()),
        );

        $traceProbe = $this->configurationTraceProcess($environment, $privateValue);
        self::assertSame(0, $traceProbe->getExitCode(), $traceProbe->getErrorOutput());
        self::assertSame('direct-safe;object-reference-present', trim($traceProbe->getOutput()));
    }

    public function test_invalid_profile_fails_without_leaking_the_raw_enum_value_to_console_or_direct_trace_arguments(): void
    {
        $privateValue = 'private-unknown-profile';
        $environment = $this->environment([
            'APP_DEBUG' => 'true',
            'AI6_SECURITY_PROFILE' => $privateValue,
        ]);
        $command = $this->process([PHP_BINARY, 'artisan', 'about'], $environment);
        $output = $command->getOutput().$command->getErrorOutput();

        self::assertNotSame(0, $command->getExitCode());
        self::assertStringContainsString('AI6_SECURITY_PROFILE', $this->withoutWhitespace($output));
        self::assertStringNotContainsString($privateValue, $output);

        $traceProbe = $this->configurationTraceProcess($environment, $privateValue);
        self::assertSame(0, $traceProbe->getExitCode(), $traceProbe->getErrorOutput());
        self::assertSame('direct-safe;object-reference-present', trim($traceProbe->getOutput()));
    }

    public function test_custom_and_development_reductions_fail_without_acknowledgement_and_start_with_it(): void
    {
        $customEnvironment = $this->environment([
            'AI6_SECURITY_PROFILE' => 'custom',
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value => 'false',
            SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value => 'false',
        ]);
        $custom = $this->process([PHP_BINARY, 'artisan', 'ai6:doctor'], $customEnvironment);
        self::assertNotSame(0, $custom->getExitCode());
        $customOutput = $custom->getOutput().$custom->getErrorOutput();
        $compactCustomOutput = $this->withoutWhitespace($customOutput);
        self::assertStringContainsString(SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value, $compactCustomOutput);
        self::assertStringContainsString(SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value, $compactCustomOutput);

        $developmentEnvironment = $this->environment([
            'AI6_SECURITY_PROFILE' => 'development',
        ]);
        $development = $this->process([PHP_BINARY, 'artisan', 'ai6:doctor'], $developmentEnvironment);
        self::assertNotSame(0, $development->getExitCode());
        self::assertStringContainsString(
            SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value,
            $this->withoutWhitespace($development->getOutput().$development->getErrorOutput()),
        );

        $developmentEnvironment['AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE'] = 'true';
        $acknowledged = $this->process([PHP_BINARY, 'artisan', 'ai6:doctor'], $developmentEnvironment);
        self::assertSame(0, $acknowledged->getExitCode(), $acknowledged->getErrorOutput());
        self::assertStringContainsString('Profil: development', $acknowledged->getOutput());
        self::assertStringContainsString(
            'Maßnahme '.SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value.': deaktiviert',
            $acknowledged->getOutput(),
        );

        foreach (SecurityMeasure::cases() as $measure) {
            if ($measure !== SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS) {
                self::assertStringContainsString('Maßnahme '.$measure->value.': aktiv', $acknowledged->getOutput());
            }
        }
    }

    public function test_security_policy_and_redactor_are_application_singletons(): void
    {
        $probe = $this->process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
exit(
    $app->make(App\AI6\Shared\Security\SecurityPolicy::class) === $app->make(App\AI6\Shared\Security\SecurityPolicy::class)
    && $app->make(App\AI6\Shared\Redaction\Redactor::class) === $app->make(App\AI6\Shared\Redaction\Redactor::class)
        ? 0
        : 1
);
PHP,
        ], $this->environment());

        self::assertSame(0, $probe->getExitCode(), $probe->getErrorOutput());
        self::assertTrue(class_exists(SecurityPolicy::class));
        self::assertTrue(class_exists(Redactor::class));
    }

    public function test_production_doctor_requires_an_explicit_versioned_redaction_keyring(): void
    {
        $environment = $this->environment([
            'APP_ENV' => 'production',
            'AI6_RUNTIME_ROLE' => 'app',
            'AI6_REDACTION_ACTIVE_KEY_ID' => 'app-key-v1',
            'AI6_REDACTION_KEYS' => '',
        ]);
        $fallback = $this->process([PHP_BINARY, 'artisan', 'about'], $environment);
        $fallbackOutput = $fallback->getOutput().$fallback->getErrorOutput();

        self::assertNotSame(0, $fallback->getExitCode(), $fallbackOutput);
        self::assertStringContainsString('AI6_REDACTION_KEYS', $this->withoutWhitespace($fallbackOutput));
        self::assertStringNotContainsString('APP_KEY-Fallback', $fallbackOutput);

        $http = $this->httpProcess($environment);
        self::assertNotSame(0, $http->getExitCode());
        self::assertSame('Interner Konfigurationsfehler.', trim($http->getOutput()));
        self::assertStringContainsString('AI6_REDACTION_KEYS', $this->withoutWhitespace($http->getErrorOutput()));

        $environment['AI6_REDACTION_ACTIVE_KEY_ID'] = 'production-v1';
        $environment['AI6_REDACTION_KEYS'] = json_encode([
            'production-v1' => [
                'version' => 1,
                'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            ],
        ], JSON_THROW_ON_ERROR);
        $explicit = $this->process([PHP_BINARY, 'artisan', 'ai6:doctor'], $environment);

        self::assertSame(0, $explicit->getExitCode(), $explicit->getErrorOutput());
        self::assertStringContainsString('Schlüsselquelle: expliziter Schlüsselring', $explicit->getOutput());
    }

    public function test_only_installation_commands_and_the_fixed_init_migration_skip_keyring_bootstrap(): void
    {
        $environment = $this->environment([
            'APP_ENV' => 'production',
            'AI6_REDACTION_KEYS' => '',
            'AI6_RUNTIME_ROLE' => 'init',
        ]);

        $ordinaryCommand = $this->process([PHP_BINARY, 'artisan', 'about'], $environment);
        self::assertNotSame(0, $ordinaryCommand->getExitCode());

        $ordinaryCommandWithGlobalOption = $this->process(
            [PHP_BINARY, 'artisan', '--no-ansi', 'about'],
            $environment,
        );
        self::assertNotSame(0, $ordinaryCommandWithGlobalOption->getExitCode());

        $migration = $this->process([PHP_BINARY, 'artisan', 'migrate', '--help'], $environment);
        self::assertSame(0, $migration->getExitCode(), $migration->getErrorOutput());

        $migrationWithGlobalOption = $this->process(
            [PHP_BINARY, 'artisan', '--no-ansi', 'migrate', '--help'],
            $environment,
        );
        self::assertSame(0, $migrationWithGlobalOption->getExitCode(), $migrationWithGlobalOption->getErrorOutput());

        $keylessEnvironment = array_replace($environment, ['APP_KEY' => '']);
        $migrationWithSeparatedEnvironment = $this->process(
            [PHP_BINARY, 'artisan', '--env', 'local', 'migrate', '--help'],
            $keylessEnvironment,
        );
        self::assertSame(
            0,
            $migrationWithSeparatedEnvironment->getExitCode(),
            $migrationWithSeparatedEnvironment->getErrorOutput(),
        );

        $keylessEnvironment['AI6_RUNTIME_ROLE'] = 'app';
        $ordinaryCommandAfterTestEnvironment = $this->process(
            [PHP_BINARY, 'artisan', '--env', 'test', 'about'],
            $keylessEnvironment,
        );
        self::assertNotSame(0, $ordinaryCommandAfterTestEnvironment->getExitCode());
        self::assertStringContainsString(
            'AI6_REDACTION_KEYS',
            $this->withoutWhitespace(
                $ordinaryCommandAfterTestEnvironment->getOutput().
                $ordinaryCommandAfterTestEnvironment->getErrorOutput(),
            ),
        );

        $environment['AI6_RUNTIME_ROLE'] = 'app';
        foreach (['key:generate', 'package:discover', 'test'] as $command) {
            $setup = $this->process([PHP_BINARY, 'artisan', $command, '--help'], $environment);
            self::assertSame(0, $setup->getExitCode(), $command.': '.$setup->getErrorOutput());
        }
    }

    public function test_security_provider_is_registered_before_application_provider(): void
    {
        $providers = require dirname(__DIR__, 4).'/bootstrap/providers.php';

        self::assertSame([
            AI6ServiceProvider::class,
            AppServiceProvider::class,
        ], $providers);
    }

    public function test_console_assertion_normalization_removes_csi_sequences_before_whitespace(): void
    {
        $wrapped = "AI6_SECURITY_REQUIRE_AGENT_\e[31mSANDBOX\e[0m\r\n true";

        self::assertSame('AI6_SECURITY_REQUIRE_AGENT_SANDBOXtrue', $this->withoutWhitespace($wrapped));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function environment(array $overrides = []): array
    {
        $environment = [
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'AI6_SECURITY_PROFILE' => 'strict',
            'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE' => 'false',
            'COLUMNS' => '80',
            'LOG_CHANNEL' => 'stderr',
        ];

        foreach (SecurityMeasure::cases() as $measure) {
            $environment[$measure->value] = 'true';
        }

        return array_replace($environment, $overrides);
    }

    /** @param list<string> $command
     * @param  array<string, string>  $environment
     */
    private function process(array $command, array $environment): Process
    {
        $process = new Process($command, dirname(__DIR__, 4), $environment);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /** @param array<string, string> $environment */
    private function httpProcess(array $environment): Process
    {
        return $this->process([
            PHP_BINARY,
            '-r',
            <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
try {
    $response = $kernel->handle(Illuminate\Http\Request::create('/health'));
    fwrite(STDOUT, (string) $response->getContent());
    exit($response->getStatusCode() === 200 ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
PHP,
        ], $environment);
    }

    private function withoutWhitespace(string $output): string
    {
        $compact = preg_replace('/\s+/u', '', $this->withoutAnsi($output));
        self::assertIsString($compact);

        return $compact;
    }

    private function withoutAnsi(string $output): string
    {
        $plain = preg_replace('/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output);
        self::assertIsString($plain);

        return $plain;
    }

    /** @param array<string, string> $environment */
    private function configurationTraceProcess(array $environment, string $privateValue): Process
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
if (ini_get('zend.exception_ignore_args') !== '1') {
    fwrite(STDOUT, 'trace-arguments-not-hidden');
    exit(4);
}
ini_set('zend.exception_ignore_args', '0');
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    exit(2);
} catch (Throwable $exception) {
    $privateValue = getenv('AI6_REVIEW_PRIVATE_VALUE');
    $containsDirectPrivateValue = function (mixed $value) use (&$containsDirectPrivateValue, $privateValue): bool {
        if (is_string($value)) {
            return is_string($privateValue) && str_contains($value, $privateValue);
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($containsDirectPrivateValue($entry)) {
                    return true;
                }
            }
        }
        return false;
    };

    $visitedObjects = new SplObjectStorage();
    $containsReferencedPrivateValue = function (mixed $value) use (&$containsReferencedPrivateValue, $privateValue, $visitedObjects): bool {
        if (is_string($value)) {
            return is_string($privateValue) && str_contains($value, $privateValue);
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if ($containsReferencedPrivateValue($entry)) {
                    return true;
                }
            }
        }
        if (is_object($value)) {
            if ($visitedObjects->contains($value)) {
                return false;
            }
            $visitedObjects->attach($value);

            $reflection = new ReflectionObject($value);
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || ! $property->isInitialized($value)) {
                    continue;
                }

                $entry = $property->getValue($value);
                if ($containsReferencedPrivateValue($entry)) {
                    return true;
                }
            }
        }
        return false;
    };

    $cyclicProbe = new stdClass();
    $cyclicProbe->self = $cyclicProbe;
    $cyclicProbe->value = $privateValue;
    $protectedProbe = new class($privateValue) {
        public function __construct(protected mixed $value) {}
    };
    if (! $containsReferencedPrivateValue($cyclicProbe) || ! $containsReferencedPrivateValue($protectedProbe)) {
        fwrite(STDOUT, 'object-traversal-failed');
        exit(3);
    }

    $trace = $exception->getTrace();
    fwrite(
        STDOUT,
        ($containsDirectPrivateValue($trace) ? 'direct-leaked' : 'direct-safe').';'.
        ($containsReferencedPrivateValue($trace) ? 'object-reference-present' : 'object-reference-missing'),
    );
    exit(0);
}
PHP;
        $environment['AI6_REVIEW_PRIVATE_VALUE'] = $privateValue;

        return $this->process([PHP_BINARY, '-r', $script], $environment);
    }
}
