<?php

namespace App\AI6\Agents;

final readonly class InstructionRecommendation
{
    public function __construct(
        public string $title,
        public string $recommendation,
        public string $reason,
    ) {}
}
