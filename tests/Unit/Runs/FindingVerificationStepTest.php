<?php

namespace Tests\Unit\Runs;

use App\AI6\Reviews\FindingVerificationRound;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use PHPUnit\Framework\TestCase;

final class FindingVerificationStepTest extends TestCase
{
    public function test_verification_is_planned_by_the_one_orchestrator_between_review_and_fix(): void
    {
        self::assertTrue(ExecutionStepType::VERIFY->hasRegisteredHandler());
        self::assertSame(
            ['type' => ExecutionStepType::VERIFY, 'number' => 1],
            RunOrchestrator::decideNextStepRound(
                RunState::RUNNING,
                null,
                ['preflight:1', 'implement:1', 'check:1', 'review:1'],
                true,
                true,
            ),
        );
        self::assertSame(
            ['type' => ExecutionStepType::FIX, 'number' => 1],
            RunOrchestrator::decideNextStepRound(
                RunState::RUNNING,
                null,
                ['preflight:1', 'implement:1', 'check:1', 'review:1', 'verify:1'],
                true,
                true,
            ),
        );
        self::assertSame(
            ['type' => ExecutionStepType::VERIFY, 'number' => 1],
            RunOrchestrator::decideReviewOnlyNextStep(
                RunState::RUNNING,
                null,
                ['review_prepare:1', 'check:1', 'review:1'],
                true,
            ),
        );
        self::assertSame(
            ['type' => ExecutionStepType::REPORT, 'number' => 1],
            RunOrchestrator::decideReviewOnlyNextStep(
                RunState::RUNNING,
                null,
                ['review_prepare:1', 'check:1', 'review:1', 'verify:1'],
                true,
            ),
        );

        $round = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Reviews/FindingVerificationRound.php');
        self::assertIsString($round);
        self::assertStringNotContainsString('ExecutionJob::query()->create', $round);
        self::assertStringNotContainsString('ExecutionStepDispatcher', $round);
        self::assertStringContainsString('RunOrchestrator $orchestrator', $round);
        self::assertTrue(class_exists(FindingVerificationRound::class));
        self::assertTrue(class_exists(ExecuteRunStep::class));
    }
}
