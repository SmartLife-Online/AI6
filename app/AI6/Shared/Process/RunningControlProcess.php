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
        private readonly ?ProcessLimits $limits = null,
        private readonly ?string $resultDirectory = null,
        private readonly ?string $artifactDirectory = null,
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
        $limitResult = null;
        $nextHeartbeat = microtime(true) + $heartbeatSeconds;
        $nextResourceCheck = microtime(true);

        while ($this->process->isRunning()) {
            $observedBytes += strlen($this->process->getIncrementalOutput());
            $observedBytes += strlen($this->process->getIncrementalErrorOutput());

            $visibleObservedBytes = max(0, $observedBytes - $this->discardOutputPrefixBytes);
            if ($visibleObservedBytes > $this->outputLimitBytes) {
                $outcome = ProcessOutcome::OUTPUT_LIMIT_EXCEEDED;
                $this->terminate();
                break;
            }

            if ($this->limits !== null && microtime(true) >= $nextResourceCheck) {
                $resourceLimit = $this->resourceLimitResult();
                if ($resourceLimit !== null) {
                    $outcome = ProcessOutcome::RESOURCE_LIMIT_EXCEEDED;
                    $limitResult = $resourceLimit;
                    $this->terminate();
                    break;
                }
                $nextResourceCheck = microtime(true) + 0.1;
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
        $limitResult ??= $this->resourceLimitResult();
        if ($limitResult !== null) {
            $outcome = ProcessOutcome::RESOURCE_LIMIT_EXCEEDED;
        }

        if ($this->terminationFailed) {
            return $this->limitedResult(ProcessOutcome::TERMINATION_FAILED, $duration, 'The control process group could not be terminated safely.');
        }

        $finalVisibleBytes = max(
            0,
            strlen($this->process->getOutput()) + strlen($this->process->getErrorOutput()) - $this->discardOutputPrefixBytes,
        );
        if ($outcome === null && $finalVisibleBytes > $this->outputLimitBytes) {
            $outcome = ProcessOutcome::OUTPUT_LIMIT_EXCEEDED;
        }

        if ($this->cancelled) {
            return $this->limitedResult(ProcessOutcome::CANCELLED, $duration, 'The control process was cancelled.');
        }

        if ($outcome === ProcessOutcome::RESOURCE_LIMIT_EXCEEDED && $limitResult !== null) {
            return $this->limitedResult($outcome, $duration, 'The control process exceeded a resource limit.', $limitResult);
        }

        if ($outcome === ProcessOutcome::OUTPUT_LIMIT_EXCEEDED) {
            return $this->limitedResult(
                $outcome,
                $duration,
                'The control process exceeded a resource limit.',
                $limitResult ?? new ProcessLimitResult(ProcessLimit::OUTPUT_BYTES, max($finalVisibleBytes, max(0, $observedBytes - $this->discardOutputPrefixBytes)), $this->outputLimitBytes),
            );
        }

        if ($outcome === ProcessOutcome::TIMED_OUT) {
            return $this->limitedResult($outcome, $duration, 'The control process exceeded its runtime limit.', new ProcessLimitResult(ProcessLimit::RUNTIME_SECONDS, (int) floor($duration) + 1, $this->timeoutSeconds));
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

    private function limitedResult(ProcessOutcome $outcome, float $duration, string $message, ?ProcessLimitResult $limitResult = null): ProcessResult
    {
        return new ProcessResult($outcome, $this->process->getExitCode(), '', $this->redact($message), $duration, $limitResult);
    }

    private function resourceLimitResult(): ?ProcessLimitResult
    {
        if ($this->limits === null) {
            return null;
        }

        [$files, $bytes] = $this->directoryInventory($this->resultDirectory);
        $artifacts = $this->directoryInventory($this->artifactDirectory)[0];
        foreach ([
            [ProcessLimit::PROCESS_COUNT, $this->processCount(), $this->limits->processCount],
            [ProcessLimit::FILE_COUNT, $files, $this->limits->fileCount],
            [ProcessLimit::TOTAL_BYTES, $bytes, $this->limits->totalBytes],
            [ProcessLimit::ARTIFACT_COUNT, $artifacts, $this->limits->artifactCount],
        ] as [$limit, $observed, $maximum]) {
            if ($observed > $maximum) {
                return new ProcessLimitResult($limit, $observed, $maximum);
            }
        }

        return null;
    }

    private function processCount(): int
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_dir('/proc')) {
            return 1;
        }

        $count = 0;
        foreach (scandir('/proc') ?: [] as $entry) {
            if (preg_match('/\A[1-9][0-9]*\z/D', $entry) !== 1) {
                continue;
            }
            $stat = @file_get_contents('/proc/'.$entry.'/stat');
            $end = is_string($stat) ? strrpos($stat, ')') : false;
            if ($end !== false && preg_match('/\A[A-Z] [0-9]+ ([0-9]+) /', substr($stat, $end + 2), $match) === 1
                && (int) $match[1] === $this->processId) {
                $count++;
            }
        }

        return max(1, $count);
    }

    /** @return array{int, int} */
    private function directoryInventory(?string $directory): array
    {
        if ($directory === null) {
            return [0, 0];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                return [PHP_INT_MAX, PHP_INT_MAX];
            }
            if ($entry->isFile()) {
                $files++;
                $bytes += $entry->getSize();
            }
        }

        return [$files, $bytes];
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
