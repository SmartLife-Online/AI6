<?php

namespace App\AI6\Agents;

final readonly class ImplementationDecision
{
    public function __construct(
        public string $key,
        public string $title,
        public string $rationale,
    ) {}
}
