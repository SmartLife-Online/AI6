<?php

namespace Tests\Unit\Checks;

use App\AI6\Checks\CheckerRuntimeConfiguration;
use RuntimeException;
use Tests\TestCase;

final class CheckerRuntimeConfigurationTest extends TestCase
{
    public function test_timeline_intervals_have_a_closed_order(): void
    {
        $configuration = new CheckerRuntimeConfiguration('/workspace', '/usr/bin/unshare', '/wrapper', 2, 2, 15, 1200, 15);
        self::assertSame(2, $configuration->pollIntervalSeconds);
        self::assertSame(1200, $configuration->executionDeadlineSeconds);

        $this->expectException(RuntimeException::class);
        new CheckerRuntimeConfiguration('/workspace', '/usr/bin/unshare', '/wrapper', 2, 15, 15, 1200, 15);
    }

    public function test_checker_policy_timeout_must_be_shorter_than_the_execution_deadline(): void
    {
        config([
            'ai6.checks.runtime' => [
                'workspace_root' => '/workspace',
                'unshare_binary' => '/usr/bin/unshare',
                'namespace_wrapper' => '/wrapper',
                'poll_interval_seconds' => 2,
                'heartbeat_interval_seconds' => 2,
                'heartbeat_max_age_seconds' => 15,
                'execution_deadline_seconds' => 900,
                'attestation_max_age_seconds' => 15,
            ],
            'ai6.process.policies.checker.timeout_seconds' => 900,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The checker process timeout must be shorter than its execution deadline.');

        CheckerRuntimeConfiguration::fromConfiguredValues();
    }
}
