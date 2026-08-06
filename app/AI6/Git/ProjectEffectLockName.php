<?php

namespace App\AI6\Git;

use App\AI6\Shared\Process\ProcessConfiguration;

final readonly class ProjectEffectLockName
{
    public function __construct(private ProcessConfiguration $configuration) {}

    public function forProject(string $projectIdentifier): string
    {
        $parts = unpack('Nindex', substr(hash('sha256', $projectIdentifier, true), 0, 4));
        $value = is_array($parts) ? (int) $parts['index'] : 0;
        $index = ($value % $this->configuration->lockObjectCount) + 1;

        return sprintf('lock-%04d', $index);
    }
}
