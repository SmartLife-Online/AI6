<?php

namespace App\AI6\Runs;

use App\AI6\Projects\ProjectConfiguration;

/**
 * The single deterministic Auto-Allow policy (TKT-007).
 *
 * Categorization reads only the path string, whether it is a deletion, the
 * ticket base path and the freigegebene project scope configuration. It never
 * inspects file content, ticket text or provider output, so the same path set
 * always yields the same decision (AC-02) and a manipulated ticket or
 * provider text cannot move a path into another category.
 */
final readonly class ScopeCategorizer
{
    /** Never auto-allowed, regardless of project configuration (AC-03). */
    private const SENSITIVE_EXACT = [
        'composer.json',
        'composer.lock',
        'Dockerfile',
        'docker-compose.yml',
    ];

    /** @var list<string> */
    private const SENSITIVE_GLOBS = [
        'database/migrations/**',
        '.github/**',
        'deploy/**',
        'docker/**',
        'app/AI6/Auth/**',
    ];

    /** Instruction paths are decided by the one instruction-path policy, never a second list. */
    public function __construct(private InstructionPathPolicy $instructionPaths) {}

    public function categorize(
        string $path,
        bool $isDeletion,
        ProjectConfiguration $configuration,
    ): ScopeCategory {
        if ($isDeletion || ! self::isCanonicalPath($path)) {
            return ScopeCategory::SENSITIVE;
        }

        $ticketsPath = $configuration->ticketsPath();
        if ($this->instructionPaths->isInstructionPath($path)
            || in_array($path, self::SENSITIVE_EXACT, true)
            || ScopePathMatcher::matchesAny($path, self::SENSITIVE_GLOBS)
            || $path === $ticketsPath
            || str_starts_with($path, $ticketsPath.'/')) {
            return ScopeCategory::SENSITIVE;
        }

        $scope = $configuration->scope();
        if (ScopePathMatcher::matchesAny($path, $scope['require_approval'])) {
            return ScopeCategory::SENSITIVE;
        }
        if (ScopePathMatcher::matchesAny($path, $scope['auto_allow'])) {
            return ScopeCategory::AUTO_ALLOW;
        }

        return ScopeCategory::UNDETERMINED;
    }

    /** A non-canonical path (traversal, empty segment, backslash, absolute) is never auto-allowed. */
    private static function isCanonicalPath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
