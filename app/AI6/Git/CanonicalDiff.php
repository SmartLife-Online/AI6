<?php

namespace App\AI6\Git;

final readonly class CanonicalDiff
{
    /** @param list<array{old_mode: string, new_mode: string, old_oid: string, new_oid: string, status: string, path: string}> $entries */
    public function __construct(
        public array $entries,
        public string $hash,
        public string $redactedPresentation,
    ) {}
}
