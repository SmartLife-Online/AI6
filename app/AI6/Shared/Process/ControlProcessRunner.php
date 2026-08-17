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
        private readonly ?ProcessPolicyRegistry $policies = null,
        private readonly ?ProcessIsolationBoundary $isolationBoundary = null,
    ) {}

    public function run(ProcessRequest $request): ProcessResult
    {
        $startedAt = microtime(true);

        try {
            return $this->start($request)->wait();
        } catch (ProcessStartRejectedException $exception) {
            return new ProcessResult(
                ProcessOutcome::START_REJECTED,
                null,
                '',
                $this->safeError($exception->getMessage(), $request),
                max(0.0, microtime(true) - $startedAt),
            );
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
        [$timeout, $outputLimit, $cancelGrace, $limits] = $this->resolvePolicy($request);
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
            $timeout,
            $outputLimit,
            $cancelGrace,
            $processId,
            $startedAt,
            terminateProcessGroup: $this->processGroupTerminator($process),
            limits: $limits,
            resultDirectory: $request->resultDirectory,
            artifactDirectory: $request->artifactDirectory,
        );
    }

    public function startBlocked(ProcessRequest $request, string $lockName): BlockedProcessStartResult
    {
        try {
            [$timeout, $outputLimit, $cancelGrace, $limits] = $this->resolvePolicy($request);
        } catch (ProcessStartRejectedException $exception) {
            return new BlockedProcessStartResult(
                BlockedStartOutcome::CONFIGURATION_ERROR,
                null,
                $exception->getMessage(),
            );
        }

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

        $deadline = microtime(true) + min($this->configuration->wrapperReadyTimeoutSeconds, $timeout);
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
                    $timeout,
                    $outputLimit,
                    $cancelGrace,
                    $processId,
                    $startedAt,
                    strlen($line.self::RELEASED_PREFIX.$processId."\n"),
                    $this->processGroupTerminator($process),
                    $limits,
                    $request->resultDirectory,
                    $request->artifactDirectory,
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

    /** @return array{int, int, int, ?ProcessLimits} */
    private function resolvePolicy(ProcessRequest $request): array
    {
        if ($this->policies === null) {
            return [
                min($request->timeoutSeconds ?? $this->configuration->timeoutSeconds, $this->configuration->timeoutSeconds),
                min($request->outputLimitBytes ?? $this->configuration->outputLimitBytes, $this->configuration->outputLimitBytes),
                $this->configuration->cancelGraceMilliseconds,
                null,
            ];
        }

        $policy = $this->policies->get($request->policy);
        if (! $this->pathWithinAny($request->workingDirectory, $policy->workingRoots)) {
            throw new ProcessStartRejectedException('The process working directory is outside its configured policy root.');
        }

        if ($request->policy !== ProcessPolicyName::CONTROL) {
            if ($this->isolationBoundary === null) {
                throw new ProcessStartRejectedException('The required process isolation boundary is not available.');
            }
            $this->isolationBoundary->assertIsolated($request, $policy);
            if ($policy->requiresProcessGroup && (DIRECTORY_SEPARATOR !== '/' || $this->configuration->setsidBinary === null)) {
                throw new ProcessStartRejectedException('The required process-group boundary is not available.');
            }
        }

        if (! in_array('*', $policy->allowedExecutables, true) && ! in_array($request->command[0], $policy->allowedExecutables, true)) {
            throw new ProcessStartRejectedException('The process executable is not allowed by its configured policy.');
        }

        foreach ($request->environmentAllowlist as $name) {
            if (! in_array($name, $policy->environmentAllowlist, true)) {
                throw new ProcessStartRejectedException('The process environment exceeds its configured policy.');
            }
        }

        $server = $this->policies->serverLimits;
        $effective = (new ProcessLimits(
            min($policy->timeoutSeconds, $server->runtimeSeconds),
            min($policy->outputLimitBytes, $server->outputBytes),
            $server->processCount,
            $server->fileCount,
            $server->totalBytes,
            $server->artifactCount,
        ))->restrict($request->approvedLimits);

        return [
            min($request->timeoutSeconds ?? $effective->runtimeSeconds, $effective->runtimeSeconds),
            min($request->outputLimitBytes ?? $effective->outputBytes, $effective->outputBytes),
            $policy->cancelGraceMilliseconds,
            $request->policy === ProcessPolicyName::CONTROL ? null : $effective,
        ];
    }

    private function pathWithin(string $path, string $root): bool
    {
        $resolvedPath = realpath($path);
        $resolvedRoot = realpath($root);

        return $resolvedPath !== false && $resolvedRoot !== false
            && ($resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, rtrim($resolvedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR));
    }

    /** @param list<string> $roots */
    private function pathWithinAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($this->pathWithin($path, $root)) {
                return true;
            }
        }

        return false;
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
                return $this->processGroupContainsOnlyZombies($processId);
            }

            if ($exitCode === 1 || str_contains($probe->getErrorOutput(), 'No such process')) {
                return true;
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function processGroupContainsOnlyZombies(int $processGroupId, string $procRoot = '/proc'): ?bool
    {
        $entries = @scandir($procRoot);
        if (! is_array($entries)) {
            return null;
        }

        $memberFound = false;
        foreach ($entries as $entry) {
            if (preg_match('/\A[1-9][0-9]*\z/D', $entry) !== 1) {
                continue;
            }

            $processDirectory = $procRoot.'/'.$entry;
            $path = $processDirectory.'/stat';
            $stat = @file_get_contents($path);
            if (! is_string($stat)) {
                if (is_dir($processDirectory)) {
                    return null;
                }

                continue;
            }

            $end = strrpos($stat, ')');
            if ($end === false
                || preg_match('/\A([A-Z]) [0-9]+ ([0-9]+) /', substr($stat, $end + 2), $matches) !== 1) {
                return null;
            }

            if ((int) $matches[2] !== $processGroupId) {
                continue;
            }

            $memberFound = true;
            if (! in_array($matches[1], ['Z', 'X'], true)) {
                return false;
            }
        }

        return $memberFound ? true : null;
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
