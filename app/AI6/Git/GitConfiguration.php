<?php

namespace App\AI6\Git;

final readonly class GitConfiguration
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $allowedRemotePaths
     * @param  list<string>  $allowedRefPatterns
     * @param  array<string, list<string>>  $pinnedHostKeyFingerprints
     */
    public function __construct(
        public string $gitBinary,
        public string $sshBinary,
        public string $executablePath,
        public string $sshWrapper,
        public string $executionHome,
        public string $xdgConfigHome,
        public string $globalConfig,
        public string $hooksPath,
        public array $allowedHosts,
        public array $allowedRemotePaths,
        public array $allowedRefPatterns,
        public array $pinnedHostKeyFingerprints,
    ) {}
}
