<?php

namespace App\AI6\Prompts;

final readonly class ManualFindingListExtraction
{
    public function __construct(
        public bool $nothingToFix,
        public string $list,
        public bool $redacted,
    ) {}
}
