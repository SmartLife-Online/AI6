<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\RefreshPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshPathPolicyTest extends TestCase
{
    public function test_canonical_path_below_server_base_is_accepted_and_normalized(): void
    {
        $policy = new RefreshPathPolicy($this->configuration());

        self::assertSame('tickets/AI6-006F.md', $policy->canonicalizeCandidate('tickets/AI6-006F.md'));
        self::assertSame('tickets/é.md', $policy->canonicalizeCandidate("tickets/e\u{0301}.md"));
        self::assertSame('tickets', $policy->basePath());
    }

    #[DataProvider('invalidCandidates')]
    public function test_untrusted_path_matrix_is_rejected_before_git_execution(string $candidate): void
    {
        $policy = new RefreshPathPolicy($this->configuration());

        $this->expectException(ControlOperationConflict::class);
        $policy->canonicalizeCandidate($candidate);
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

    private function configuration(): ControlOperationConfiguration
    {
        return new ControlOperationConfiguration(
            '/managed',
            '/managed/keys',
            '/usr/bin/ssh-keygen',
            '/wrapper',
            120,
            30,
            30,
            3,
            '/managed/known_hosts',
            ['refs/heads/main'],
            300,
            8,
            'tickets',
        );
    }
}
