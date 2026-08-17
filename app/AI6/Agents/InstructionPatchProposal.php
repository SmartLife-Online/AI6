<?php

namespace App\AI6\Agents;

final readonly class InstructionPatchProposal
{
    public function __construct(public string $targetPath, public string $content, public string $hash) {}
}
