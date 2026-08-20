<?php

namespace Tests\Unit\Checks;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProcessResultInterpreter;
use App\AI6\Checks\CheckProfile;
use App\AI6\Checks\CheckProfileWorkingDirectory;
use App\AI6\Checks\CheckResultState;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class CheckProcessResultInterpreterTest extends TestCase
{
    #[DataProvider('specificFailures')]
    public function test_every_specific_process_and_boundary_failure_keeps_its_reason(ProcessOutcome $outcome, ?int $exitCode, CheckResultState $state, string $reason): void
    {
        [$actualState, $actualReason] = $this->app->make(CheckProcessResultInterpreter::class)->outcome(
            $this->profile(), new ProcessResult($outcome, $exitCode, '', '', 0.1),
            str_repeat('a', 64), str_repeat('a', 64),
        );

        self::assertSame($state, $actualState);
        self::assertSame($reason, $actualReason);
    }

    /** @return iterable<string, array{ProcessOutcome, ?int, CheckResultState, string}> */
    public static function specificFailures(): iterable
    {
        yield 'output limit' => [ProcessOutcome::OUTPUT_LIMIT_EXCEEDED, null, CheckResultState::FAILED, 'output_limit_exceeded'];
        yield 'resource limit' => [ProcessOutcome::RESOURCE_LIMIT_EXCEEDED, null, CheckResultState::FAILED, 'resource_limit_exceeded'];
        yield 'cancelled' => [ProcessOutcome::CANCELLED, null, CheckResultState::FAILED, 'process_cancelled'];
        yield 'termination failed' => [ProcessOutcome::TERMINATION_FAILED, null, CheckResultState::FAILED, 'process_termination_failed'];
        yield 'wrapper contract' => [ProcessOutcome::FAILED, 78, CheckResultState::FAILED, 'checker_boundary_contract_invalid'];
        yield 'pid namespace' => [ProcessOutcome::FAILED, 79, CheckResultState::FAILED, 'checker_pid_namespace_missing'];
        yield 'protected path missing' => [ProcessOutcome::FAILED, 80, CheckResultState::FAILED, 'checker_protected_path_missing'];
        yield 'workspace boundary' => [ProcessOutcome::FAILED, 81, CheckResultState::FAILED, 'checker_workspace_boundary_invalid'];
        yield 'protected path visible' => [ProcessOutcome::FAILED, 82, CheckResultState::FAILED, 'checker_protected_path_visible'];
    }

    public function test_baseline_and_tree_mutations_are_distinct_failures(): void
    {
        $interpreter = $this->app->make(CheckProcessResultInterpreter::class);
        $success = new ProcessResult(ProcessOutcome::SUCCEEDED, 0, '', '', 0.1);

        self::assertSame(
            [CheckResultState::FAILED, 'baseline_mutated'],
            $interpreter->outcome($this->profile(), $success, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), str_repeat('d', 64)),
        );
        self::assertSame(
            [CheckResultState::FAILED, 'unexpected_tree_mutation'],
            $interpreter->outcome($this->profile(), $success, str_repeat('a', 64), str_repeat('b', 64)),
        );
    }

    public function test_only_a_typed_start_rejection_may_reach_persisted_output(): void
    {
        $interpreter = $this->app->make(CheckProcessResultInterpreter::class);

        $rejected = $interpreter->startFailure(new ProcessStartRejectedException('The checker namespace boundary is unavailable.'));
        self::assertSame(ProcessOutcome::START_REJECTED, $rejected->outcome);
        self::assertSame('The checker namespace boundary is unavailable.', $rejected->errorOutput);

        $internal = $interpreter->startFailure(new RuntimeException('Internal path: /var/lib/ai6/private-root'));
        self::assertSame(ProcessOutcome::START_FAILED, $internal->outcome);
        self::assertSame('The control process could not be started.', $internal->errorOutput);
        self::assertStringNotContainsString('/var/lib/ai6/private-root', $internal->errorOutput);
    }

    private function profile(): CheckProfile
    {
        return new CheckProfile(
            'probe', PHP_BINARY, ['probe.php'], [CheckPhase::BEFORE_REVIEW],
            CheckProfileWorkingDirectory::TREE, [0], false, false, false,
        );
    }
}
