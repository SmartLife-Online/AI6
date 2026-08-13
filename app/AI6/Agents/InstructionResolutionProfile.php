<?php

namespace App\AI6\Agents;

final readonly class InstructionResolutionProfile
{
    /** @param array<string, InstructionDiscovery> $discoveries */
    public function __construct(
        public string $providerProfileAlias,
        public array $discoveries,
    ) {}
}
