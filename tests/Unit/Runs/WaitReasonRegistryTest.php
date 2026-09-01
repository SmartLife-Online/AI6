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

    public function test_a_second_different_status_sync_contract_is_rejected(): void
    {
        $registry = new WaitReasonRegistry;
        $registry->register(WaitReason::STATUS_SYNC, 'CompletionStatusSaga', ['refresh_expected_oid'], true);

        try {
            $registry->register(WaitReason::STATUS_SYNC, 'PublishCompletionService', ['refresh_expected_oid'], true);
            self::fail('A conflicting second status-sync registration was accepted.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('wait_reason_registration_conflict', $conflict->reason);
        }

        self::assertSame([
            'producer' => 'CompletionStatusSaga',
            'resolvers' => ['refresh_expected_oid'],
            'cancellable' => true,
        ], $registry->registration(WaitReason::STATUS_SYNC));
    }
}
