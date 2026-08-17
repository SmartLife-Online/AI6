<?php

namespace App\AI6\Shared\Process;

final readonly class ProcessPolicy
{
    /** @param list<string> $allowedExecutables
     * @param  list<string>  $environmentAllowlist
     * @param  list<string>  $workingRoots
     */
    public function __construct(
        public ProcessPolicyName $name,
        public int $timeoutSeconds,
        public int $outputLimitBytes,
        public array $allowedExecutables,
        public array $environmentAllowlist,
        public array $workingRoots,
        public bool $requiresProcessGroup,
        public int $cancelGraceMilliseconds,
    ) {}
}
