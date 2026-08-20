<?php

namespace App\AI6\Checks;

use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Throwable;

/** The single database-free mapping from process evidence to a check outcome. */
final readonly class CheckProcessResultInterpreter
{
    private const COMMAND_NOT_FOUND = 127;

    /** @var array<int, string> */
    private const CHECKER_BOUNDARY_REASONS = [
        78 => 'checker_boundary_contract_invalid',
        79 => 'checker_pid_namespace_missing',
        80 => 'checker_protected_path_missing',
        81 => 'checker_workspace_boundary_invalid',
        82 => 'checker_protected_path_visible',
    ];

    public function __construct(private Redactor $redactor) {}

    public function startFailure(Throwable $exception): ProcessResult
    {
        return new ProcessResult(
            $exception instanceof ProcessStartRejectedException ? ProcessOutcome::START_REJECTED : ProcessOutcome::START_FAILED,
            null,
            '',
            $exception instanceof ProcessStartRejectedException
                ? $exception->getMessage()
                : 'The control process could not be started.',
            0.0,
        );
    }

    /** @return array{CheckResultState, ?string} */
    public function outcome(
        CheckProfile $profile,
        ProcessResult $result,
        string $treeBefore,
        string $treeAfter,
        ?string $baselineBefore = null,
        ?string $baselineAfter = null,
    ): array {
        if ($baselineBefore !== null && $baselineAfter !== null && ! hash_equals($baselineBefore, $baselineAfter)) {
            return [CheckResultState::FAILED, 'baseline_mutated'];
        }
        if ($result->exitCode !== null && isset(self::CHECKER_BOUNDARY_REASONS[$result->exitCode])) {
            return [CheckResultState::FAILED, self::CHECKER_BOUNDARY_REASONS[$result->exitCode]];
        }

        $state = match (true) {
            $result->outcome === ProcessOutcome::TIMED_OUT => CheckResultState::TIMED_OUT,
            in_array($result->outcome, [ProcessOutcome::START_FAILED, ProcessOutcome::START_REJECTED], true) => CheckResultState::TOOL_UNAVAILABLE,
            $result->exitCode === self::COMMAND_NOT_FOUND || ! $this->executable($profile) => CheckResultState::TOOL_UNAVAILABLE,
            $result->exitCode !== null && in_array($result->exitCode, $profile->successExitCodes, true) => CheckResultState::SUCCEEDED,
            default => CheckResultState::FAILED,
        };
        if ($state === CheckResultState::SUCCEEDED && ! $profile->mutatesTree && ! hash_equals($treeBefore, $treeAfter)) {
            return [CheckResultState::FAILED, 'unexpected_tree_mutation'];
        }

        return [$state, match ($state) {
            CheckResultState::SUCCEEDED => null,
            CheckResultState::TIMED_OUT => 'process_timeout',
            CheckResultState::TOOL_UNAVAILABLE => $result->outcome === ProcessOutcome::START_REJECTED
                ? 'process_start_rejected'
                : 'check_tool_unavailable',
            default => match ($result->outcome) {
                ProcessOutcome::OUTPUT_LIMIT_EXCEEDED => 'output_limit_exceeded',
                ProcessOutcome::RESOURCE_LIMIT_EXCEEDED => 'resource_limit_exceeded',
                ProcessOutcome::CANCELLED => 'process_cancelled',
                ProcessOutcome::TERMINATION_FAILED => 'process_termination_failed',
                default => 'check_failed',
            },
        }];
    }

    public function safeOutput(ProcessResult $result, RedactionContext $context): string
    {
        try {
            return $this->redactor->redact(rtrim($result->output."\n".$result->errorOutput), $context)->text;
        } catch (InvalidRedactionInputException) {
            return 'Die Checkausgabe war kein gültiges UTF-8 und wurde verworfen.';
        }
    }

    private function executable(CheckProfile $profile): bool
    {
        return is_file($profile->program) && is_executable($profile->program);
    }
}
