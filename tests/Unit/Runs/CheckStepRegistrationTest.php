<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use Tests\TestCase;

/**
 * TC-09 registration half: the check step is a delivered step type, and
 * check_failure carries exactly one producer with its resolvers and cancel path.
 */
final class CheckStepRegistrationTest extends TestCase
{
    public function test_the_check_step_type_has_a_registered_handler_and_follows_the_implement_step(): void
    {
        self::assertTrue(ExecutionStepType::CHECK->hasRegisteredHandler());
        self::assertSame(
            ['preflight', 'implement', 'check', 'review', 'fix'],
            array_map(static fn (ExecutionStepType $type): string => $type->value, ExecutionStepType::cases()),
        );

        self::assertSame(
            ['type' => ExecutionStepType::CHECK, 'number' => 1],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, ['preflight:1', 'implement:1'], false),
        );
        self::assertSame(
            ['type' => ExecutionStepType::REVIEW, 'number' => 1],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, ['preflight:1', 'implement:1', 'check:1'], false),
        );
        self::assertNull(RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, ['preflight:1', 'implement:1', 'check:1', 'review:1'], false));
        self::assertNull(RunOrchestrator::decideNextStepRound(RunState::WAITING, WaitReason::CHECK_FAILURE, ['preflight:1', 'implement:1'], false));
    }

    public function test_the_check_step_key_is_deterministic_and_distinct(): void
    {
        $run = '2f1d4a3c-0000-4000-8000-000000000001';
        $key = RunOrchestrator::stepKey($run, ExecutionStepType::CHECK, 1);

        self::assertSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::CHECK, 1));
        self::assertNotSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::IMPLEMENT, 1));
        self::assertNotSame($key, RunOrchestrator::stepKey($run, ExecutionStepType::CHECK, 2));
    }

    /** AC-11: producer, resolvers and cancel path are registered together. */
    public function test_check_failure_is_registered_with_producer_resolvers_and_cancel_path(): void
    {
        $registry = $this->app->make(WaitReasonRegistry::class);

        self::assertTrue($registry->isRegistered(WaitReason::CHECK_FAILURE));
        self::assertSame(
            // RunCheckStep is what parks the run; CheckRunner only executes one profile.
            ['producer' => 'RunCheckStep', 'resolvers' => ['retry_unchanged_tree', 'orchestrator_code_fix'], 'cancellable' => true],
            $registry->registration(WaitReason::CHECK_FAILURE),
        );
    }

    /** A producer without any resolution is refused, so the registration cannot become inert. */
    public function test_a_producer_without_resolver_and_without_cancel_path_is_refused(): void
    {
        $registry = new WaitReasonRegistry;

        $this->expectException(RunTransitionConflict::class);
        $registry->register(WaitReason::CHECK_FAILURE, 'RunCheckStep', [], false);
    }
}
