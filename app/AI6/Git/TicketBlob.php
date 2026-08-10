<?php

namespace App\AI6\Git;

final readonly class TicketBlob
{
    public function __construct(
        public string $relativePath,
        public string $blobSha,
        public string $content,
    ) {}
}
