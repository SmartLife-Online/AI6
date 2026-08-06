<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Throwable;

final class RunningControlProcess
{
    private bool $cancelled = false;

    private bool $terminationFailed = false;

    public function __construct(
        private readonly Process $process,
        private readonly Redactor $redactor,
        private readonly RedactionContext $redactionContext,
        private readonly int $timeoutSeconds,
        private readonly int $outputLimitBytes,
        private readonly int $cancelGraceMilliseconds,
        public readonly int $processId,
        public readonly float $startedAt,
        private readonly int $discardOutputPrefixBytes = 0,
        /** @var null|Closure(int, int): bool */
        private readonly ?Closure $terminateProcessGroup = null,
    ) {}

    public function running(): bool
    {
        return $this->process->isRunning();
    }

    public function waitForOutputPrefix(string $prefix, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (str_starts_with($this->process->getOutput(), $prefix)) {
                return true;
            }

            if (! $this->process->isRunning()) {
                return false;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function cancel(): void
    {
        if (! $this->process->isRunning()) {
            return;
        }

        $this->cancelled = true;
        $this->terminate();
    }

    /** @param null|Closure(): void $heartbeat */
    public function wait(?Closure $heartbeat = null, int $heartbeatSeconds = 1): ProcessResult
    {
        if ($heartbeatSeconds < 1) {
            throw new \InvalidArgumentException('The process heartbeat interval must be positive.');
        }

        $observedBytes = 0;
        $outcome = null;
        $nextHeartbeat = microtime(true) + $heartbeatSeconds;

        while ($this->process->isRunning()) {
            $observedBytes += strlen($this->process->getIncrementalOutput());
            $observedBytes += strlen($this->process->getIncrementalErrorOutput());

            if ($observedBytes > $this->outputLimitBytes + $this->discardOutputPrefixBytes) {
                $outcome = ProcessOutcome::OUTPUT_LIMIT_EXCEEDED;
                $this->terminate();
                break;
            }

            if ((microtime(true) - $this->startedAt) >= $this->timeoutSeconds) {
                $outcome = ProcessOutcome::TIMED_OUT;
                $this->terminate();
                break;
            }

            if ($heartbeat !== null && microtime(true) >= $nextHeartbeat) {
                $heartbeat();
                $nextHeartbeat = microtime(true) + $heartbeatSeconds;
            }

            usleep(10000);
        }

        if (! $this->terminationFailed || ! $this->process->isRunning()) {
            try {
                $this->process->wait();
            } catch (ProcessSignaledException) {
                // A signal is an expected terminal state for group cancellation and abrupt termination.
            }
        }
        $duration = max(0.0, microtime(true) - $this->startedAt);

        if ($this->terminationFailed) {
            return $this->limitedResult(ProcessOutcome::TERMINATION_FAILED, $duration, 'The control process group could not be terminated safely.');
        }

        if ($outcome === null
            && (strlen($this->process->getOutput()) + strlen($this->process->getErrorOutput())) > $this->outputLimitBytes + $this->discardOutputPrefixBytes) {
            $outcome = ProcessOutcome::OUTPUT_LIMIT_EXCEEDED;
        }

        if ($this->cancelled) {
            return $this->limitedResult(ProcessOutcome::CANCELLED, $duration, 'The control process was cancelled.');
        }

        if ($outcome === ProcessOutcome::OUTPUT_LIMIT_EXCEEDED) {
            return $this->limitedResult($outcome, $duration, 'The control process exceeded its output limit.');
        }

        if ($outcome === ProcessOutcome::TIMED_OUT) {
            return $this->limitedResult($outcome, $duration, 'The control process exceeded its runtime limit.');
        }

        $output = $this->process->getOutput();
        if ($this->discardOutputPrefixBytes > 0) {
            $output = substr($output, $this->discardOutputPrefixBytes);
        }

        $error = $this->redact($this->process->getErrorOutput());
        $exitCode = $this->process->getExitCode();

        return new ProcessResult(
            $exitCode === 0 ? ProcessOutcome::SUCCEEDED : ProcessOutcome::FAILED,
            $exitCode,
            $output,
            $error,
            $duration,
        );
    }

    private function limitedResult(ProcessOutcome $outcome, float $duration, string $message): ProcessResult
    {
        return new ProcessResult($outcome, $this->process->getExitCode(), '', $this->redact($message), $duration);
    }

    private function terminate(): void
    {
        if ($this->terminateProcessGroup !== null) {
            try {
                $terminated = ($this->terminateProcessGroup)($this->processId, $this->cancelGraceMilliseconds);
            } catch (Throwable) {
                $terminated = false;
            }

            if ($terminated) {
                return;
            }

            $this->terminationFailed = true;
        }

        if ($this->process->isRunning()) {
            try {
                $this->process->stop($this->cancelGraceMilliseconds / 1000, 9);
            } catch (Throwable) {
                $this->terminationFailed = true;
            }
        }
    }

    private function redact(string $error): string
    {
        if ($error === '') {
            return '';
        }

        try {
            return $this->redactor->redact($error, $this->redactionContext)->text;
        } catch (InvalidRedactionInputException) {
            return 'The control process produced invalid error output.';
        }
    }
}
