<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunStepDecisionTest extends TestCase
{
    /**
     * TC-01: exactly one next step follows from state, wait status and completed steps.
     *
     * @return list<array{RunState, WaitReason|null, list<string>, ExecutionStepType|null}>
     */
    public static function decisions(): array
    {
        return [
            [RunState::QUEUED, null, [], ExecutionStepType::PREFLIGHT],
            [RunState::RUNNING, null, [], ExecutionStepType::PREFLIGHT],
            [RunState::RUNNING, null, ['preflight'], ExecutionStepType::IMPLEMENT],
            [RunState::RUNNING, null, ['preflight', 'implement'], ExecutionStepType::CHECK],
            [RunState::RUNNING, null, ['preflight', 'implement', 'check'], ExecutionStepType::REVIEW],
            [RunState::RUNNING, null, ['preflight', 'implement', 'check', 'review'], ExecutionStepType::FINALIZE],
            [RunState::RUNNING, null, ['preflight', 'implement', 'check', 'review', 'finalize'], ExecutionStepType::SECURITY_REVIEW],
            [RunState::RUNNING, null, ['preflight', 'implement', 'check', 'review', 'finalize', 'security_review'], ExecutionStepType::PUBLISH],
            [RunState::WAITING, WaitReason::HUMAN_QUESTION, ['preflight'], null],
            [RunState::FAILED, null, [], null],
            [RunState::COMPLETED, null, ['preflight'], null],
            [RunState::CANCELLED, null, [], null],
        ];
    }

    /**
     * @param  list<string>  $completed
     */
    #[DataProvider('decisions')]
    public function test_the_next_step_decision_is_deterministic(
        RunState $state,
        ?WaitReason $waitReason,
        array $completed,
        ?ExecutionStepType $expected,
    ): void {
        $coordinates = array_map(static fn (string $type): string => $type.':1', $completed);
        $actual = RunOrchestrator::decideNextStepRound($state, $waitReason, $coordinates, false);
        self::assertSame($expected, $actual['type'] ?? null);
        self::assertSame(
            $actual,
            RunOrchestrator::decideNextStepRound($state, $waitReason, $coordinates, false),
        );
    }

    public function test_a_wait_reason_stops_the_decision_even_in_a_running_state(): void
    {
        self::assertSame(
            ExecutionStepType::PREFLIGHT,
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, [], false)['type'],
        );
        self::assertNull(RunOrchestrator::decideNextStepRound(RunState::RUNNING, WaitReason::HUMAN_QUESTION, [], false));
    }

    /** TC-02: the idempotency key is a pure function of run, step type and number. */
    public function test_the_step_key_is_deterministic_and_separates_every_coordinate(): void
    {
        $run = '2f1d4a3c-0000-4000-8000-000000000001';
        $key = RunOrchestrator::stepKey($run, ExecutionStepType::PREFLIGHT, 1);

        self::assertSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::PREFLIGHT, 1));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $key);
        self::assertNotSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::IMPLEMENT, 1));
        self::assertNotSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::PREFLIGHT, 2));
        self::assertNotSame($key, RunOrchestrator::stepKey('2f1d4a3c-0000-4000-8000-000000000002', ExecutionStepType::PREFLIGHT, 1));
    }

    public function test_only_a_step_type_with_a_registered_handler_may_be_delivered(): void
    {
        self::assertTrue(ExecutionStepType::PREFLIGHT->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::IMPLEMENT->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::CHECK->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::REVIEW->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::FIX->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::FINALIZE->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::SECURITY_REVIEW->hasRegisteredHandler());
        self::assertTrue(ExecutionStepType::PUBLISH->hasRegisteredHandler());
    }

    public function test_a_fix_repeats_checks_checkpoint_readiness_and_the_complete_review_sequence(): void
    {
        $initial = ['preflight:1', 'implement:1', 'check:1', 'review:1'];
        self::assertSame(
            ['type' => ExecutionStepType::FIX, 'number' => 1],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, $initial, true),
        );
        self::assertSame(
            ['type' => ExecutionStepType::CHECK, 'number' => 2],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, [...$initial, 'fix:1'], true),
        );
        self::assertSame(
            ['type' => ExecutionStepType::REVIEW, 'number' => 2],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, [...$initial, 'fix:1', 'check:2'], true),
        );
        self::assertSame(
            ['type' => ExecutionStepType::FINALIZE, 'number' => 1],
            RunOrchestrator::decideNextStepRound(
                RunState::RUNNING,
                null,
                [...$initial, 'fix:1', 'check:2', 'review:2'],
                false,
            ),
        );
        self::assertSame(
            ['type' => ExecutionStepType::FIX, 'number' => 2],
            RunOrchestrator::decideNextStepRound(
                RunState::RUNNING,
                null,
                [...$initial, 'fix:1', 'check:2', 'review:2'],
                true,
            ),
        );
    }
}
