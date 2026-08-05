<?php

namespace App\AI6\Git;

final readonly class ValidatedGitRemote
{
    public function __construct(
        public string $remote,
        public string $host,
        public string $path,
        public string $ref,
    ) {}
}
