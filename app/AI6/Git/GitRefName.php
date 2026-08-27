<?php

namespace App\AI6\Git;

/**
 * The one syntactic rule for a managed Git ref name.
 *
 * Whether a syntactically valid ref is also permitted stays with
 * `GitRemotePolicy`, which applies the configured allowlist on top of this
 * grammar. Only the grammar lives here, so a second consumer — the bound review
 * subject of `GIT-011` — cannot drift from the rule the runner enforces.
 */
final readonly class GitRefName
{
    public static function valid(string $ref): bool
    {
        return preg_match('/\Arefs\/(?:heads|tags)\/[A-Za-z0-9._\/-]+\z/D', $ref) === 1
            && ! str_contains($ref, '..')
            && ! str_contains($ref, '//')
            && ! str_ends_with($ref, '.lock');
    }
}
