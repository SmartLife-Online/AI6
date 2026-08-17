<?php

namespace App\AI6\Agents;

final readonly class HumanRequestOption
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
