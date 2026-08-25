<?php

namespace Tests\Unit\Reviews;

use App\AI6\Reviews\ReviewResultParseException;
use App\AI6\Reviews\ReviewStallFingerprint;
use PHPUnit\Framework\TestCase;

final class ReviewStallFingerprintTest extends TestCase
{
    public function test_fingerprint_ignores_finding_order_and_retries_but_binds_diff_progress(): void
    {
        $fingerprints = new ReviewStallFingerprint;
        $first = str_repeat('1', 64);
        $second = str_repeat('2', 64);
        $diff = str_repeat('a', 64);

        self::assertSame(
            $fingerprints->fromGroups([$first, $second], $diff),
            $fingerprints->fromGroups([$second, $first, $first], $diff),
        );
        self::assertNotSame(
            $fingerprints->fromGroups([$first, $second], $diff),
            $fingerprints->fromGroups([$first, $second], str_repeat('b', 64)),
        );
    }

    public function test_unbound_fingerprint_input_is_rejected(): void
    {
        $this->expectException(ReviewResultParseException::class);

        (new ReviewStallFingerprint)->fromGroups([str_repeat('1', 64)], 'not-a-diff-hash');
    }
}
