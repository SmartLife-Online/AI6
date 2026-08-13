<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\RefreshPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshPathPolicyTest extends TestCase
{
    public function test_canonical_path_below_server_base_is_accepted_and_normalized(): void
    {
        self::assertSame('tickets/AI6-006F.md', RefreshPathPolicy::canonicalizeCandidateForBase('tickets/AI6-006F.md', 'tickets'));
        self::assertSame('tickets/é.md', RefreshPathPolicy::canonicalizeCandidateForBase("tickets/e\u{0301}.md", 'tickets'));
    }

    #[DataProvider('invalidCandidates')]
    public function test_untrusted_path_matrix_is_rejected_before_git_execution(string $candidate): void
    {
        $this->expectException(ControlOperationConflict::class);
        RefreshPathPolicy::canonicalizeCandidateForBase($candidate, 'tickets');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCandidates(): iterable
    {
        yield 'absolute' => ['/tickets/AI6-006F.md'];
        yield 'drive absolute' => ['C:/tickets/AI6-006F.md'];
        yield 'parent segment' => ['tickets/../secret.md'];
        yield 'dot segment' => ['tickets/./AI6-006F.md'];
        yield 'backslash' => ['tickets\\AI6-006F.md'];
        yield 'control byte' => ["tickets/AI6\n006F.md"];
        yield 'outside base' => ['other/AI6-006F.md'];
        yield 'prefix collision' => ['tickets-extra/AI6-006F.md'];
        yield 'base itself' => ['tickets'];
        yield 'double separator' => ['tickets//AI6-006F.md'];
    }
}
