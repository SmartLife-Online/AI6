<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ScopePathMatcher;
use PHPUnit\Framework\TestCase;

/** TC-01 */
final class ScopePathMatcherTest extends TestCase
{
    public function test_double_star_matches_any_depth_below_a_prefix(): void
    {
        self::assertTrue(ScopePathMatcher::matches('app/AI6/Runs/Foo.php', 'app/**'));
        self::assertTrue(ScopePathMatcher::matches('app', 'app/**'));
        self::assertFalse(ScopePathMatcher::matches('resources/views/runs/x.blade.php', 'app/**'));
    }

    public function test_single_segment_wildcards_stay_within_one_segment(): void
    {
        self::assertTrue(ScopePathMatcher::matches('tickets/AI6-020.md', 'tickets/*.md'));
        self::assertFalse(ScopePathMatcher::matches('tickets/nested/AI6-020.md', 'tickets/*.md'));
    }

    public function test_matches_any_short_circuits_on_first_hit(): void
    {
        self::assertTrue(ScopePathMatcher::matchesAny('tests/Unit/Foo.php', ['app/**', 'tests/**']));
        self::assertFalse(ScopePathMatcher::matchesAny('AGENTS.md', ['app/**', 'tests/**']));
    }
}
