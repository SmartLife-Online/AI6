<?php

namespace App\AI6\Agents;

final readonly class InstructionCandidate
{
    /** @param list<string> $imports */
    public function __construct(
        public string $discoveryName,
        public InstructionCandidateOrigin $origin,
        public bool $exists,
        public InstructionFileType $fileType,
        public string $repositoryPath,
        public string $blobSha,
        public string $content,
        public array $imports = [],
    ) {}
}
