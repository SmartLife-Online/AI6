<?php

namespace Tests\Unit\Runs;

use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use PHPUnit\Framework\TestCase;

final class ReviewStepRegistrationTest extends TestCase
{
    public function test_review_is_the_single_registered_step_after_checks(): void
    {
        self::assertTrue(ExecutionStepType::REVIEW->hasRegisteredHandler());
        self::assertSame(
            ['type' => ExecutionStepType::REVIEW, 'number' => 1],
            RunOrchestrator::decideNextStepRound(RunState::RUNNING, null, ['preflight:1', 'implement:1', 'check:1'], false),
        );

        $job = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Runs/Jobs/ExecuteRunStep.php');
        self::assertIsString($job);
        self::assertSame(3, substr_count($job, 'ExecutionStepType::REVIEW'));
        self::assertStringContainsString('ReviewRound::class', $job);
    }

    public function test_review_round_does_not_create_a_second_step_planner(): void
    {
        $round = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Reviews/ReviewRound.php');
        self::assertIsString($round);
        self::assertStringNotContainsString('ExecutionJob::query()->create', $round);
        self::assertStringNotContainsString('ExecutionStepDispatcher', $round);
        self::assertStringNotContainsString('Run::query()->update', $round);
        self::assertStringContainsString('RunOrchestrator $orchestrator', $round);
        self::assertTrue(class_exists(ReviewRound::class));
        self::assertTrue(class_exists(ExecuteRunStep::class));
    }
}
