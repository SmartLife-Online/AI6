<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConfigurationFactory;
use App\AI6\Git\ProjectEffectLockName;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Process\ProcessConfiguration;
use PHPUnit\Framework\TestCase;

final class ControlOperationConfigurationTest extends TestCase
{
    public function test_strict_configuration_accepts_the_complete_safe_contract(): void
    {
        $result = (new ControlOperationConfigurationFactory)->inspect($this->configuration());

        self::assertInstanceOf(ControlOperationConfiguration::class, $result);
        self::assertSame('/managed', $result->managedRoot);
        self::assertSame('/managed/keys', $result->keyRoot);
        self::assertSame(120, $result->leaseSeconds);
        self::assertSame(30, $result->heartbeatSeconds);
    }

    public function test_key_root_escape_and_non_strict_or_incoherent_intervals_are_rejected(): void
    {
        foreach ([
            ['key_root' => '/other/keys'],
            ['lease_seconds' => '120seconds'],
            ['heartbeat_seconds' => '120'],
            ['max_attempts' => 0],
        ] as $overrides) {
            self::assertInstanceOf(
                ConfigurationViolation::class,
                (new ControlOperationConfigurationFactory)->inspect(array_replace($this->configuration(), $overrides)),
            );
        }
    }

    public function test_project_lock_mapping_is_deterministic_and_within_the_preprovisioned_range(): void
    {
        $configuration = new ProcessConfiguration(
            300,
            1024,
            100,
            30,
            '/wrapper',
            '/bin/dash',
            null,
            null,
            '/locks',
            64,
            1000,
            0,
        );
        $mapping = new ProjectEffectLockName($configuration);

        $name = $mapping->forProject(str_repeat('a', 32));
        self::assertSame($name, $mapping->forProject(str_repeat('a', 32)));
        self::assertMatchesRegularExpression('/\Alock-[0-9]{4}\z/D', $name);
        self::assertGreaterThanOrEqual(1, (int) substr($name, 5));
        self::assertLessThanOrEqual(64, (int) substr($name, 5));
    }

    public function test_effect_lock_wait_must_be_shorter_than_wrapper_readiness_timeout(): void
    {
        $process = new ProcessConfiguration(
            300,
            1024,
            100,
            30,
            '/wrapper',
            '/bin/dash',
            null,
            null,
            '/locks',
            64,
            30000,
            0,
        );

        self::assertInstanceOf(
            ConfigurationViolation::class,
            (new ControlOperationConfigurationFactory($process))->inspect($this->configuration()),
        );
    }

    /** @return array<string, string> */
    private function configuration(): array
    {
        return [
            'managed_root' => '/managed',
            'key_root' => '/managed/keys',
            'ssh_keygen_binary' => '/usr/bin/ssh-keygen',
            'ssh_keygen_wrapper' => '/opt/ai6/keygen.sh',
            'lease_seconds' => '120',
            'heartbeat_seconds' => '30',
            'reconciler_seconds' => '30',
            'max_attempts' => '3',
        ];
    }
}
