<?php

namespace App\AI6\Agents;

final readonly class AgentFinding
{
    /** @param list<string> $criterionRefs */
    public function __construct(
        public string $localId,
        public string $severity,
        public string $disposition,
        public string $category,
        public string $file,
        public int $line,
        public string $title,
        public string $evidence,
        public string $expectedResult,
        public array $criterionRefs,
    ) {}
}
