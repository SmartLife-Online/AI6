<?php

namespace App\AI6\Prompts;

final readonly class PromptEntry
{
    /** @param list<string> $requiredVariables */
    public function __construct(
        public string $id,
        public string $version,
        public string $template,
        public array $requiredVariables,
        public string $displayName = '',
    ) {}
}
