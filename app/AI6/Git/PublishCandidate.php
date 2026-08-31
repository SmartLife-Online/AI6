<?php

namespace App\AI6\Git;

final readonly class PublishCandidate
{
    public function __construct(
        public string $treeOid,
        public string $diffHash,
        public string $baseSha,
    ) {}
}
