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
            [RunState::RUNNING, null, ['preflight', 'implement', 'check'], null],
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
        self::assertSame($expected, RunOrchestrator::decideNextStep($state, $waitReason, $completed));
        self::assertSame(
            RunOrchestrator::decideNextStep($state, $waitReason, $completed),
            RunOrchestrator::decideNextStep($state, $waitReason, $completed),
        );
    }

    public function test_a_wait_reason_stops_the_decision_even_in_a_running_state(): void
    {
        self::assertSame(
            ExecutionStepType::PREFLIGHT,
            RunOrchestrator::decideNextStep(RunState::RUNNING, null, []),
        );
        self::assertNull(RunOrchestrator::decideNextStep(RunState::RUNNING, WaitReason::HUMAN_QUESTION, []));
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
    }
}
