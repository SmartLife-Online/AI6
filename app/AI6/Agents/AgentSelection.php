<?php

namespace App\AI6\Agents;

final readonly class AgentSelection
{
    public function __construct(
        public AgentProfile $profile,
        public AgentRole $role,
        public string $model,
        public string $effort,
    ) {}
}
