<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

final class ControlProcessRunner
{
    private const READY_PREFIX = '__AI6_PROCESS_READY_V1__:';

    private const RELEASED_PREFIX = '__AI6_PROCESS_RELEASED_V1__:';

    public function __construct(
        private readonly ProcessConfiguration $configuration,
        private readonly Redactor $redactor,
        private readonly EffectLock $effectLock,
    ) {}

    public function run(ProcessRequest $request): ProcessResult
    {
        $startedAt = microtime(true);

        try {
            return $this->start($request)->wait();
        } catch (Throwable) {
            return new ProcessResult(
                ProcessOutcome::START_FAILED,
                null,
                '',
                $this->safeError('The control process could not be started.', $request),
                max(0.0, microtime(true) - $startedAt),
            );
        }
    }

    /** @throws RuntimeException */
    public function start(ProcessRequest $request): RunningControlProcess
    {
        $command = DIRECTORY_SEPARATOR === '/'
            ? $this->wrapperCommand(['direct', '--', ...$request->command])
            : $request->command;
        $process = new Process($command, $request->workingDirectory, $this->environment($request), null, null);
        $process->start();
        $startedAt = $process->getStartTime();
        $processId = $process->getPid();

        if ($processId === null) {
            $process->stop(0);
            throw new RuntimeException('The control process has no process identifier.');
        }

        return new RunningControlProcess(
            $process,
            $this->redactor,
            $request->redactionContext,
            min($request->timeoutSeconds ?? $this->configuration->timeoutSeconds, $this->configuration->timeoutSeconds),
            min($request->outputLimitBytes ?? $this->configuration->outputLimitBytes, $this->configuration->outputLimitBytes),
            $this->configuration->cancelGraceMilliseconds,
            $processId,
            $startedAt,
            terminateProcessGroup: $this->processGroupTerminator($process),
        );
    }

    public function startBlocked(ProcessRequest $request, string $lockName): BlockedProcessStartResult
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return new BlockedProcessStartResult(
                BlockedStartOutcome::CONFIGURATION_ERROR,
                null,
                'Blocked control process starts require a POSIX runtime.',
            );
        }

        $lock = $this->effectLock->validate($lockName);
        if (! $lock->valid() || $lock->path === null || $lock->identity === null) {
            $outcome = $lock->outcome === EffectLockOutcome::CONFIGURATION_ERROR
                ? BlockedStartOutcome::CONFIGURATION_ERROR
                : BlockedStartOutcome::LOCK_CONFLICT;

            return new BlockedProcessStartResult($outcome, null, $lock->message);
        }

        $input = new InputStream;
        $command = $this->wrapperCommand([
            'blocked',
            $lock->path,
            $lock->identity,
            number_format($this->configuration->lockWaitMilliseconds / 1000, 3, '.', ''),
            '--',
            ...$request->command,
        ]);
        $process = new Process($command, $request->workingDirectory, $this->environment($request), $input, null);

        try {
            $process->start();
        } catch (Throwable) {
            return new BlockedProcessStartResult(BlockedStartOutcome::START_FAILED, null, 'The blocked control process could not be started.');
        }

        $startedAt = $process->getStartTime();

        $processId = $process->getPid();
        if ($processId === null) {
            $process->stop(0);

            return new BlockedProcessStartResult(BlockedStartOutcome::START_FAILED, null, 'The blocked control process has no process identifier.');
        }

        $deadline = microtime(true) + min($this->configuration->wrapperReadyTimeoutSeconds, $request->timeoutSeconds ?? $this->configuration->timeoutSeconds);
        $protocol = '';
        do {
            $protocol .= $process->getIncrementalOutput();
            if (($newline = strpos($protocol, "\n")) !== false) {
                $line = substr($protocol, 0, $newline + 1);
                $expected = self::READY_PREFIX.$processId."\n";
                if (! hash_equals($expected, $line)) {
                    $process->stop(0, 9);

                    return new BlockedProcessStartResult(BlockedStartOutcome::LOCK_CONFLICT, null, 'The blocked process readiness binding is invalid.');
                }

                $running = new RunningControlProcess(
                    $process,
                    $this->redactor,
                    $request->redactionContext,
                    min($request->timeoutSeconds ?? $this->configuration->timeoutSeconds, $this->configuration->timeoutSeconds),
                    min($request->outputLimitBytes ?? $this->configuration->outputLimitBytes, $this->configuration->outputLimitBytes),
                    $this->configuration->cancelGraceMilliseconds,
                    $processId,
                    $startedAt,
                    strlen($line.self::RELEASED_PREFIX.$processId."\n"),
                    $this->processGroupTerminator($process),
                );

                return new BlockedProcessStartResult(
                    BlockedStartOutcome::READY,
                    new BlockedControlProcess(
                        $running,
                        $input,
                        $processId,
                        $startedAt,
                        $line.self::RELEASED_PREFIX.$processId."\n",
                        $this->configuration->wrapperReadyTimeoutSeconds,
                    ),
                    'The blocked control process is ready for release.',
                );
            }

            if (! $process->isRunning()) {
                $process->wait();

                return match ($process->getExitCode()) {
                    75 => new BlockedProcessStartResult(BlockedStartOutcome::LOCK_UNAVAILABLE, null, 'The effect lock is currently unavailable.'),
                    76 => new BlockedProcessStartResult(BlockedStartOutcome::LOCK_CONFLICT, null, 'The effect lock object identity changed.'),
                    default => new BlockedProcessStartResult(BlockedStartOutcome::START_FAILED, null, $this->safeError($process->getErrorOutput(), $request)),
                };
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        $process->stop(0, 9);

        return new BlockedProcessStartResult(BlockedStartOutcome::START_FAILED, null, 'The blocked process readiness limit was exceeded.');
    }

    /** @param list<string> $arguments
     * @return non-empty-list<string>
     */
    private function wrapperCommand(array $arguments): array
    {
        $command = [$this->configuration->shellBinary, $this->configuration->wrapperScript, ...$arguments];

        if ($this->configuration->setsidBinary !== null) {
            $command = [$this->configuration->setsidBinary, '--', ...$command];
        }

        return $command;
    }

    /** @return array<string, string|false> */
    private function environment(ProcessRequest $request): array
    {
        $current = getenv();
        $environment = [];

        foreach ($current as $name => $value) {
            $environment[$name] = false;
        }

        foreach ($request->environmentAllowlist as $name) {
            if (array_key_exists($name, $request->environment)) {
                $environment[$name] = $request->environment[$name];
            } elseif (isset($current[$name])) {
                $environment[$name] = $current[$name];
            }
        }

        return $environment;
    }

    /** @return null|Closure(int, int): bool */
    private function processGroupTerminator(Process $target): ?Closure
    {
        if (DIRECTORY_SEPARATOR !== '/'
            || $this->configuration->setsidBinary === null
            || $this->configuration->processGroupKillBinary === null) {
            return null;
        }

        return function (int $processId, int $graceMilliseconds) use ($target): bool {
            $termSucceeded = $this->signalProcessGroup($processId, 'TERM');

            if ($graceMilliseconds > 0) {
                usleep($graceMilliseconds * 1000);
            }

            $killSucceeded = $this->signalProcessGroup($processId, 'KILL');

            return $this->waitForEmptyProcessGroup($processId, $target, $termSucceeded || $killSucceeded);
        };
    }

    private function waitForEmptyProcessGroup(int $processId, Process $target, bool $signalSucceeded): bool
    {
        $deadline = microtime(true) + 1;
        do {
            $target->isRunning();
            $empty = $this->processGroupIsEmpty($processId);
            if ($empty === true) {
                return true;
            }

            if ($empty === null && ! $signalSucceeded) {
                return false;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function processGroupIsEmpty(int $processId): ?bool
    {
        $killBinary = $this->configuration->processGroupKillBinary;
        if ($killBinary === null) {
            return null;
        }

        try {
            $probe = new Process(
                [$killBinary, '-0', '--', '-'.$processId],
                null,
                $this->clearedEnvironment(),
                null,
                2,
            );
            $exitCode = $probe->run();
            if ($exitCode === 0) {
                return false;
            }

            if ($exitCode === 1 || str_contains($probe->getErrorOutput(), 'No such process')) {
                return true;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function signalProcessGroup(int $processId, string $signal): bool
    {
        $killBinary = $this->configuration->processGroupKillBinary;
        if ($killBinary === null) {
            return false;
        }

        try {
            $process = new Process(
                [$killBinary, '-'.$signal, '--', '-'.$processId],
                null,
                $this->clearedEnvironment(),
                null,
                2,
            );

            return $process->run() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string|false> */
    private function clearedEnvironment(): array
    {
        $environment = [];

        foreach (getenv() as $name => $value) {
            $environment[$name] = false;
        }

        $environment['LC_ALL'] = 'C';
        $environment['LANG'] = 'C';

        return $environment;
    }

    private function safeError(string $error, ProcessRequest $request): string
    {
        if ($error === '') {
            return 'The control process failed before producing a safe diagnostic.';
        }

        try {
            return $this->redactor->redact($error, $request->redactionContext)->text;
        } catch (InvalidRedactionInputException) {
            return 'The control process produced invalid error output.';
        }
    }
}
