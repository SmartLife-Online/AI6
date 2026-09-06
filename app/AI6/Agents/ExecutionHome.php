<?php

namespace App\AI6\Agents;

final readonly class ExecutionHome
{
    /** @param array<string, string|null> $workspaceProjection Worker-owned native hashes; null denotes an omitted file. */
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
        public array $workspaceProjection = [],
    ) {}
}
