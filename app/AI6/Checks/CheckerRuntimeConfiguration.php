<?php

namespace App\AI6\Checks;

use RuntimeException;

final readonly class CheckerRuntimeConfiguration
{
    public function __construct(
        public string $workspaceRoot,
        public string $unshareBinary,
        public string $namespaceWrapper,
        public int $pollIntervalSeconds,
        public int $heartbeatIntervalSeconds,
        public int $heartbeatMaxAgeSeconds,
        public int $executionDeadlineSeconds,
        public int $attestationMaxAgeSeconds,
    ) {
        if ($workspaceRoot === '' || $unshareBinary === '' || $namespaceWrapper === ''
            || $pollIntervalSeconds < 1 || $heartbeatIntervalSeconds < 1 || $pollIntervalSeconds > $heartbeatIntervalSeconds
            || $heartbeatMaxAgeSeconds <= $heartbeatIntervalSeconds
            || $executionDeadlineSeconds <= $heartbeatMaxAgeSeconds
            || $attestationMaxAgeSeconds < $heartbeatIntervalSeconds
            || $attestationMaxAgeSeconds > $executionDeadlineSeconds) {
            throw new RuntimeException('The checker runtime configuration is invalid.');
        }
    }

    public static function fromConfiguredValues(): self
    {
        $value = config('ai6.checks.runtime');
        if (! is_array($value)) {
            throw new RuntimeException('The checker runtime configuration is missing.');
        }
        foreach (['workspace_root', 'unshare_binary', 'namespace_wrapper'] as $path) {
            if (! is_string($value[$path] ?? null)) {
                throw new RuntimeException('The checker runtime path configuration is invalid.');
            }
        }
        $integers = [];
        foreach (['poll_interval_seconds', 'heartbeat_interval_seconds', 'heartbeat_max_age_seconds', 'execution_deadline_seconds', 'attestation_max_age_seconds'] as $name) {
            $configured = $value[$name] ?? null;
            if ((! is_int($configured) && ! is_string($configured)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $configured) !== 1) {
                throw new RuntimeException('The checker runtime interval configuration is invalid.');
            }
            $integers[$name] = (int) $configured;
        }
        $policyTimeout = config('ai6.process.policies.checker.timeout_seconds');
        if ((! is_int($policyTimeout) && ! is_string($policyTimeout))
            || preg_match('/\A[1-9][0-9]*\z/D', (string) $policyTimeout) !== 1
            || (int) $policyTimeout >= $integers['execution_deadline_seconds']) {
            throw new RuntimeException('The checker process timeout must be shorter than its execution deadline.');
        }

        return new self(
            $value['workspace_root'], $value['unshare_binary'], $value['namespace_wrapper'],
            $integers['poll_interval_seconds'], $integers['heartbeat_interval_seconds'],
            $integers['heartbeat_max_age_seconds'], $integers['execution_deadline_seconds'],
            $integers['attestation_max_age_seconds'],
        );
    }
}
