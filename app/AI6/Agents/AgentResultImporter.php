<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Redaction\RedactionContext;

final readonly class AgentResultImporter
{
    public function __construct(
        private AgentResultValidator $validator,
        private InstructionPatchChannel $patchChannel,
    ) {}

    public function validate(string $bytes, AgentResultContext $context, RedactionContext $redactionContext): AgentResult
    {
        return $this->validator->validate($bytes, $context, $redactionContext);
    }

    public function importInstructionPatch(
        string $bytes,
        AgentResultContext $context,
        RedactionContext $redactionContext,
        ExecutionHome $home,
    ): AgentResult {
        $result = $this->validator->validate($bytes, $context, $redactionContext);
        if ($result->instructionPatch !== null) {
            $this->patchChannel->propose(
                $home,
                $context->instructionSnapshot,
                $context->initialScope,
                $result->instructionPatch->path,
                $result->instructionPatch->content,
                $redactionContext,
                array_keys(array_filter(
                    $context->expectedInstructionBlobs,
                    static fn (?string $sha): bool => $sha === null,
                )),
            );
        }

        return $result;
    }
}
