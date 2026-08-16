<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use PHPUnit\Framework\TestCase;

final class WaitReasonRegistryTest extends TestCase
{
    public function test_an_unpaired_producer_is_rejected_and_a_pair_is_idempotent(): void
    {
        $registry = new WaitReasonRegistry;

        try {
            $registry->register(WaitReason::MANUAL_GATE, 'gate');
            self::fail('Expected an unpaired producer conflict.');
        } catch (RunTransitionConflict $exception) {
            self::assertSame('unpaired_wait_reason_producer', $exception->reason);
        }

        $registry->register(WaitReason::MANUAL_GATE, 'gate', ['evidence']);
        $registry->register(WaitReason::MANUAL_GATE, 'gate', ['evidence']);
        self::assertTrue($registry->isRegistered(WaitReason::MANUAL_GATE));
    }
}
