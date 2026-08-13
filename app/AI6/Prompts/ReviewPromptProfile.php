<?php

namespace App\AI6\Prompts;

final readonly class ReviewPromptProfile
{
    public function __construct(
        public string $id,
        public string $version,
        public string $displayName,
        public string $focus,
    ) {}
}
