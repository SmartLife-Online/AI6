<?php

namespace App\AI6\Agents;

final readonly class CriterionCoverageEntry
{
    public function __construct(
        public string $criterionId,
        public string $status,
        public string $evidence,
    ) {}
}
