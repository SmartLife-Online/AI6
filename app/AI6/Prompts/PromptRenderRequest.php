<?php

namespace App\AI6\Prompts;

final readonly class PromptRenderRequest
{
    public function __construct(
        public string $entryId,
        public PromptVariables $variables,
        public ?string $reviewProfileId = null,
    ) {}
}
