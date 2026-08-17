<?php

namespace App\AI6\Agents;

final readonly class InstructionPatch
{
    public function __construct(
        public string $path,
        public ?string $expectedBlobSha,
        public string $format,
        public string $content,
        public int $contentLength,
        public string $contentSha256,
    ) {}
}
