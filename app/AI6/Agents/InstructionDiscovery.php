<?php

namespace App\AI6\Agents;

final readonly class InstructionDiscovery
{
    public function __construct(
        public string $name,
        public int $priority,
        public string $scope,
    ) {}
}
