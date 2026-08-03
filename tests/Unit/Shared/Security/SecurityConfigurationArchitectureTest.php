<?php

namespace Tests\Unit\Shared\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SecurityConfigurationArchitectureTest extends TestCase
{
    public function test_application_code_reads_security_configuration_only_in_the_approved_boundary(): void
    {
        $root = dirname(__DIR__, 4);
        $violations = [];

        foreach (['app', 'bootstrap', 'config', 'routes', 'scripts'] as $directory) {
            $directoryPath = $root.'/'.$directory;

            if (! is_dir($directoryPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryPath, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains($path, '/app/AI6/Shared/Security/')
                    || str_ends_with($path, '/config/ai6.php')
                ) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertNotFalse($source);

                if ($this->readsSecurityConfigurationDirectly($source)) {
                    $violations[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function test_detector_covers_helpers_facades_and_superglobals_with_both_quote_styles(): void
    {
        foreach ([
            "env('AI6_SECURITY_PROFILE')",
            'getenv("AI6_SECURITY_REQUIRE_AGENT_SANDBOX")',
            "config('ai6.security.profile')",
            'config("ai6.security.measures")',
            "Config::get('ai6.security.profile')",
            'Config::boolean("ai6.security.acknowledge_reduced_mode")',
            "\$_ENV['AI6_SECURITY_PROFILE']",
            '\$_SERVER["AI6_SECURITY_PROFILE"]',
        ] as $source) {
            self::assertTrue($this->readsSecurityConfigurationDirectly($source), $source);
        }

        self::assertFalse($this->readsSecurityConfigurationDirectly('$policy->isEnabled($measure)'));
    }

    private function readsSecurityConfigurationDirectly(string $source): bool
    {
        return preg_match(
            '/(?:env|getenv|config)\s*\(\s*[\'\"][^\'\"]*(?:AI6_SECURITY_|ai6\.security)/is',
            $source,
        ) === 1
            || preg_match(
                '/Config::(?:get|string|boolean|integer|array)\s*\(\s*[\'\"]ai6\.security/is',
                $source,
            ) === 1
            || preg_match('/\$_(?:ENV|SERVER)\s*\[\s*[\'\"]AI6_SECURITY_/', $source) === 1;
    }
}
