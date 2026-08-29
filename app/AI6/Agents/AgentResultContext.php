<?php

namespace App\AI6\Agents;

use App\AI6\Prompts\PromptSnapshot;

final readonly class AgentResultContext
{
    /**
     * @param  list<string>  $criterionRefs
     * @param  list<string>  $initialScope
     * @param  array<string, string|null>  $expectedInstructionBlobs
     * @param  list<string>  $expectedFindingIds
     * @param  list<string>  $expectedFindingGroups
     */
    public function __construct(
        public AgentRole $role,
        public PromptSnapshot $promptSnapshot,
        public InstructionSnapshot $instructionSnapshot,
        public ProviderRuntimeProfile $runtimeProfile,
        public array $criterionRefs,
        public string $actualDiff,
        public bool $instructionUpdate = false,
        public array $initialScope = [],
        public array $expectedInstructionBlobs = [],
        public string $slotId = '',
        public int $attempt = 1,
        public array $expectedFindingIds = [],
        public array $expectedFindingGroups = [],
    ) {}
}
