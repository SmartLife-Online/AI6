<?php

namespace App\AI6\Git;

final readonly class TicketReadModelRefreshResult
{
    public function __construct(
        public string $operationId,
        public int $projectId,
        public string $relativePath,
        public string $controlCommit,
        public string $blobSha,
        public string $content,
    ) {}
}
