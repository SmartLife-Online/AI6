<?php

namespace App\AI6\Git;

final readonly class RunPatchChange
{
    public function __construct(
        public string $path,
        public RunPatchStatus $status,
        public int $bytes,
    ) {}
}
