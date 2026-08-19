<?php

namespace Tests\Unit\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Runs\EffectiveScope;
use PHPUnit\Framework\TestCase;

/** TC-08 */
final class EffectiveScopeTest extends TestCase
{
    public function test_effective_scope_is_the_union_and_deterministically_hashed(): void
    {
        $canonicalJson = new CanonicalJson;
        $a = EffectiveScope::compute(['app/AI6/Runs/'], ['app/AI6/Git/Foo.php'], $canonicalJson);
        $b = EffectiveScope::compute(['app/AI6/Runs/'], ['app/AI6/Git/Foo.php'], $canonicalJson);

        self::assertSame(['app/AI6/Git/Foo.php', 'app/AI6/Runs/'], $a->effectiveScope);
        self::assertSame($a->hash, $b->hash);
        self::assertTrue($a->contains('app/AI6/Git/Foo.php'));
        self::assertFalse($a->contains('AGENTS.md'));
    }

    public function test_a_different_addition_set_yields_a_different_hash(): void
    {
        $canonicalJson = new CanonicalJson;
        $a = EffectiveScope::compute(['app/AI6/Runs/'], [], $canonicalJson);
        $b = EffectiveScope::compute(['app/AI6/Runs/'], ['app/AI6/Git/Foo.php'], $canonicalJson);

        self::assertNotSame($a->hash, $b->hash);
    }
}
