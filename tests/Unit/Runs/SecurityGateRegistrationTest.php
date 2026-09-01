<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use Tests\TestCase;

final class SecurityGateRegistrationTest extends TestCase
{
    public function test_security_step_and_complete_wait_contract_are_registered(): void
    {
        self::assertTrue(ExecutionStepType::SECURITY_REVIEW->hasRegisteredHandler());
        self::assertSame([
            'producer' => 'SecurityReviewStep',
            'resolvers' => ['bound_clear', 'step_up_override'],
            'cancellable' => true,
        ], $this->app->make(WaitReasonRegistry::class)->registration(WaitReason::SECURITY_GATE));
        self::assertSame([
            'producer' => 'PublishCompletionService',
            'resolvers' => ['authorize_push'],
            'cancellable' => true,
        ], $this->app->make(WaitReasonRegistry::class)->registration(WaitReason::MANUAL_PUSH));
        self::assertSame([
            'producer' => 'CompletionStatusSaga',
            'resolvers' => ['refresh_expected_oid'],
            'cancellable' => true,
        ], $this->app->make(WaitReasonRegistry::class)->registration(WaitReason::STATUS_SYNC));
    }

    public function test_an_incomplete_security_gate_registration_is_rejected(): void
    {
        $this->expectException(RunTransitionConflict::class);
        (new WaitReasonRegistry)->register(WaitReason::SECURITY_GATE, 'SecurityReviewStep');
    }

    public function test_finalized_candidate_never_reenters_fix_planning_for_a_security_finding(): void
    {
        $completed = ['preflight:1', 'implement:1', 'check:1', 'review:1', 'finalize:1'];
        self::assertSame(
            ['type' => ExecutionStepType::SECURITY_REVIEW, 'number' => 1],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, $completed, true, true),
        );
        self::assertSame(
            ['type' => ExecutionStepType::PUBLISH, 'number' => 1],
            RunOrchestrator::decideNextStepRound(
                RunState::RUNNING,
                null,
                [...$completed, 'security_review:1'],
                true,
                true,
            ),
        );
    }
}
