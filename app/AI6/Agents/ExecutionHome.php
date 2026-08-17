<?php

namespace App\AI6\Agents;

final readonly class ExecutionHome
{
    public function __construct(
        public string $root,
        public string $outputRoot,
        public string $workspace,
        public string $home,
        public string $instructionOverlay,
        public string $runtimeConfiguration,
        public string $authDirectory,
        public string $resultDirectory,
        public string $artifactDirectory,
        public string $patchDirectory,
    ) {}
}
