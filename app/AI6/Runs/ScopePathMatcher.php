<?php

namespace App\AI6\Runs;

use App\AI6\Projects\ProjectConfigurationParser;

/**
 * Deterministic glob matching for scope paths (AGT-009-style, no shell globbing).
 *
 * Patterns are already validated by {@see ProjectConfigurationParser}
 * to consist only of literal segments, `*`/`?` wildcards inside a segment, or a
 * literal `**` segment matching zero or more path segments. Matching therefore
 * never touches the filesystem and never depends on untrusted content, only on
 * the server-validated pattern list and the candidate path string.
 */
final class ScopePathMatcher
{
    /** @param list<string> $scope */
    public static function coveredBy(string $path, array $scope): bool
    {
        foreach ($scope as $entry) {
            if ($path === $entry || str_starts_with($path, rtrim($entry, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $path, string $pattern): bool
    {
        return self::matchSegments(explode('/', $path), explode('/', $pattern));
    }

    /**
     * @param  list<string>  $pathSegments
     * @param  list<string>  $patternSegments
     */
    private static function matchSegments(array $pathSegments, array $patternSegments): bool
    {
        if ($patternSegments === []) {
            return $pathSegments === [];
        }

        $head = array_shift($patternSegments);
        if ($head === '**') {
            if ($patternSegments === []) {
                return true;
            }
            for ($skip = 0; $skip <= count($pathSegments); $skip++) {
                if (self::matchSegments(array_slice($pathSegments, $skip), $patternSegments)) {
                    return true;
                }
            }

            return false;
        }

        if ($pathSegments === []) {
            return false;
        }
        $pathHead = array_shift($pathSegments);
        if (fnmatch($head, $pathHead, FNM_NOESCAPE) !== true) {
            return false;
        }

        return self::matchSegments($pathSegments, $patternSegments);
    }
}
