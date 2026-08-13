<?php

namespace App\AI6\Git;

final readonly class ControlOperationConfiguration
{
    public function __construct(
        public string $managedRoot,
        public string $keyRoot,
        public string $sshKeygenBinary,
        public string $sshKeygenWrapper,
        public int $leaseSeconds,
        public int $heartbeatSeconds,
        public int $reconcilerSeconds,
        public int $maxAttempts,
        public string $knownHostsFile,
        /** @var non-empty-list<string> */
        public array $managedRefAllowlist,
        public int $staleSeconds,
        public int $reconciliationBudget,
    ) {}
}
