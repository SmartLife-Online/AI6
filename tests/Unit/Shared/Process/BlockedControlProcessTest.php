<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\BlockedStartOutcome;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

final class BlockedControlProcessTest extends TestCase
{
    private ?string $fixture = null;

    private ?string $lockDirectory = null;

    public function test_effect_waits_for_release_and_exec_keeps_the_reported_pid(): void
    {
        $runner = $this->posixRunner();
        $effect = $this->fixture.'/effect';
        $request = $this->request([
            PHP_BINARY,
            '-r',
            'file_put_contents($argv[1], "effect"); usleep(300000); echo getmypid();',
            $effect,
        ]);

        $start = $runner->startBlocked($request, 'lock-0001');
        self::assertSame(BlockedStartOutcome::READY, $start->outcome, $start->message);
        self::assertNotNull($start->process);
        self::assertFileDoesNotExist($effect);
        self::assertGreaterThan(0, $start->process->processId);
        self::assertLessThanOrEqual(microtime(true), $start->process->startedAt);

        $pid = $start->process->processId;
        $start->process->release();
        $this->waitForFile($effect);
        self::assertSame(realpath(PHP_BINARY), realpath('/proc/'.$pid.'/exe'));
        $result = $start->process->wait();
        self::assertTrue($result->succeeded(), $result->errorOutput);
        self::assertSame((string) $pid, $result->output);
        self::assertSame('effect', file_get_contents($effect));
    }

    public function test_wrapper_and_direct_uses_serialize_and_timeout_with_the_same_named_result(): void
    {
        $runner = $this->posixRunner(lockWaitMilliseconds: 100);
        $first = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'usleep(100000);']), 'lock-0001');
        self::assertTrue($first->ready(), $first->message);

        $other = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "parallel";']), 'lock-0006');
        self::assertTrue($other->ready(), $other->message);
        $other->process?->release();
        self::assertSame('parallel', $other->process?->wait()->output);

        $second = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "unexpected";']), 'lock-0001');
        self::assertSame(BlockedStartOutcome::LOCK_UNAVAILABLE, $second->outcome);
        self::assertFalse($this->effectLock(lockWaitMilliseconds: 100)->acquire('lock-0001', 30)->acquired());

        $first->process?->release();
        self::assertTrue($first->process?->wait()->succeeded());
        $third = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "acquired";']), 'lock-0001');
        self::assertTrue($third->ready(), $third->message);
        $third->process?->release();
        self::assertSame('acquired', $third->process?->wait()->output);

        $direct = $this->effectLock(lockWaitMilliseconds: 100)->acquire('lock-0001', 50);
        self::assertTrue($direct->acquired());
        self::assertSame(
            BlockedStartOutcome::LOCK_UNAVAILABLE,
            $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "unexpected";']), 'lock-0001')->outcome,
        );
        $direct->handle?->release();
    }

    public function test_cancel_terminates_the_complete_process_group_without_a_partial_work_state(): void
    {
        $runner = $this->posixRunner();
        $childPidFile = $this->fixture.'/child-pid';
        $childReady = $this->fixture.'/child-ready';
        $workState = $this->fixture.'/work-state';
        $childCode = <<<'PHP'
pcntl_async_signals(true);
pcntl_signal(SIGTERM, SIG_IGN);
file_put_contents($argv[1], 'ready');
while (true) { usleep(100000); }
PHP;
        $code = <<<'PHP'
$child = proc_open([PHP_BINARY, '-r', $argv[4], $argv[2]], [], $pipes);
$status = is_resource($child) ? proc_get_status($child) : false;
if (! is_array($status) || ! is_int($status['pid'])) { exit(3); }
file_put_contents($argv[1], (string) $status['pid']);
file_put_contents($argv[3], 'started');
usleep(5000000);
file_put_contents($argv[3], 'finished');
PHP;
        $running = $runner->start($this->request([
            PHP_BINARY,
            '-r',
            $code,
            $childPidFile,
            $childReady,
            $workState,
            $childCode,
        ]));
        $this->waitForFile($childPidFile);
        $this->waitForFile($childReady);
        $childPid = (int) file_get_contents($childPidFile);
        self::assertDirectoryExists('/proc/'.$childPid);

        $running->cancel();
        self::assertSame(ProcessOutcome::CANCELLED, $running->wait()->outcome);
        $this->waitForProcessExit($childPid);
        self::assertFalse($this->processIsRunning($childPid));
        self::assertSame('started', file_get_contents($workState));
    }

    public function test_process_group_sigkill_releases_the_unchanged_lock_object(): void
    {
        $runner = $this->posixRunner();
        $identity = stat($this->lockDirectory.'/lock-0001');
        self::assertIsArray($identity);
        $childPidFile = $this->fixture.'/locked-child-pid';
        $code = <<<'PHP'
$child = proc_open([PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], [], $pipes);
$status = is_resource($child) ? proc_get_status($child) : false;
if (! is_array($status) || ! is_int($status['pid'])) { exit(3); }
file_put_contents($argv[1], (string) $status['pid']);
while (true) { usleep(100000); }
PHP;
        $first = $runner->startBlocked($this->request([PHP_BINARY, '-r', $code, $childPidFile]), 'lock-0001');
        self::assertTrue($first->ready(), $first->message);
        $processId = $first->process->processId;
        $first->process->release();
        $this->waitForFile($childPidFile);
        $childPid = (int) file_get_contents($childPidFile);

        $kill = new Process(['/usr/bin/kill', '-KILL', '--', '-'.$processId]);
        $kill->mustRun();
        $first->process->wait();
        $this->waitForProcessExit($childPid);

        $after = stat($this->lockDirectory.'/lock-0001');
        self::assertIsArray($after);
        self::assertSame($identity['ino'], $after['ino']);
        $next = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "released";']), 'lock-0001');
        self::assertTrue($next->ready(), $next->message);
        $next->process?->release();
        self::assertSame('released', $next->process?->wait()->output);
    }

    public function test_non_exec_supervisor_counterexample_releases_the_lock_while_its_target_still_runs(): void
    {
        $this->posixRunner();
        $badWrapper = $this->fixture.'/non-exec-wrapper.sh';
        file_put_contents($badWrapper, <<<'SH'
#!/bin/sh
set -eu
lock_path=$1
shift
exec 9<"$lock_path"
/usr/bin/flock --exclusive 9
"$@" 9<&- &
printf '%s\n' "$!"
while true; do :; done
SH);
        chmod($badWrapper, 0555);
        $supervisor = new Process([
            '/usr/bin/setsid',
            '--',
            '/usr/bin/dash',
            $badWrapper,
            $this->lockDirectory.'/lock-0001',
            PHP_BINARY,
            '-r',
            'while (true) { usleep(100000); }',
        ]);
        $supervisor->start();
        $deadline = microtime(true) + 2;
        $output = '';
        while (! str_contains($output, "\n") && microtime(true) < $deadline) {
            $output .= $supervisor->getIncrementalOutput();
            usleep(10000);
        }
        $childPid = (int) trim($output);
        self::assertGreaterThan(0, $childPid);
        self::assertTrue($this->processIsRunning($childPid));

        $processId = $supervisor->getPid();
        self::assertIsInt($processId);
        (new Process(['/usr/bin/kill', '-KILL', '--', (string) $processId]))->mustRun();
        try {
            $supervisor->wait();
        } catch (ProcessSignaledException) {
            // The deliberately unsafe supervisor is expected to die by SIGKILL.
        }

        $unsafeAcquisition = $this->effectLock(lockWaitMilliseconds: 100)->acquire('lock-0001', 100);
        self::assertTrue($unsafeAcquisition->acquired(), 'The counterexample must expose early lock release.');
        self::assertTrue($this->processIsRunning($childPid), 'The counterexample target must still be running.');
        $unsafeAcquisition->handle?->release();

        (new Process(['/usr/bin/kill', '-KILL', '--', '-'.$processId]))->run();
        $this->waitForProcessExit($childPid);
    }

    public function test_cleanup_based_wrapper_counterexample_stays_locked_after_group_sigkill(): void
    {
        $this->posixRunner();
        $cleanupLock = $this->fixture.'/cleanup-lock';
        $badWrapper = $this->fixture.'/cleanup-wrapper.sh';
        file_put_contents($badWrapper, <<<'SH'
#!/usr/bin/dash
set -eu
[ "${1:-}" = "blocked" ] || exit 78
: "${AI6_CLEANUP_LOCK_PATH:?AI6_CLEANUP_LOCK_PATH is required}"
(set -C; : > "$AI6_CLEANUP_LOCK_PATH") 2>/dev/null || exit 75
trap 'unlink -- "$AI6_CLEANUP_LOCK_PATH"' TERM
printf '__AI6_PROCESS_READY_V1__:%s\n' "$$"
while true; do sleep 1; done
SH);
        chmod($badWrapper, 0555);
        $configuration = $this->configuration(500, $badWrapper);
        $runner = new ControlProcessRunner($configuration, $this->redactor(), new EffectLock($configuration));
        $start = $runner->startBlocked(
            $this->request(
                [PHP_BINARY, '-r', 'echo "must-not-run";'],
                ['AI6_CLEANUP_LOCK_PATH' => $cleanupLock],
            ),
            'lock-0001',
        );
        self::assertTrue($start->ready(), $start->message);
        self::assertNotNull($start->process);
        self::assertTrue(is_file($cleanupLock), 'The cleanup-based wrapper must hold its marker lock before termination.');

        (new Process(['/usr/bin/kill', '-KILL', '--', '-'.$start->process->processId]))->mustRun();
        $start->process->wait();

        self::assertTrue(is_file($cleanupLock), 'SIGKILL must expose the stale marker left by cleanup-based locking.');
        $next = @fopen($cleanupLock, 'x');
        self::assertFalse($next, 'A regular cleanup-based acquisition must fail while the stale marker still exists.');
    }

    public function test_a_direct_lock_is_released_by_the_holding_process_exit(): void
    {
        $runner = $this->posixRunner();
        $heldMarker = $this->fixture.'/direct-held';
        $owner = fileowner($this->lockDirectory);
        self::assertIsInt($owner);
        $code = <<<'PHP'
require $argv[1];
$configuration = new App\AI6\Shared\Process\ProcessConfiguration(
    10,
    4096,
    100,
    2,
    $argv[2],
    '/usr/bin/dash',
    '/usr/bin/setsid',
    '/usr/bin/kill',
    $argv[3],
    6,
    500,
    (int) $argv[4],
);
$lock = (new App\AI6\Shared\Process\EffectLock($configuration))->acquire('lock-0001', 500);
if (! $lock->acquired()) { exit(4); }
file_put_contents($argv[5], 'held');
while (true) { usleep(100000); }
PHP;
        $holder = $runner->start($this->request([
            PHP_BINARY,
            '-r',
            $code,
            dirname(__DIR__, 4).'/vendor/autoload.php',
            dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            $this->lockDirectory,
            (string) $owner,
            $heldMarker,
        ]));
        $this->waitForFile($heldMarker);
        self::assertSame(
            BlockedStartOutcome::LOCK_UNAVAILABLE,
            $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "unexpected";']), 'lock-0001')->outcome,
        );

        (new Process(['/usr/bin/kill', '-KILL', '--', '-'.$holder->processId]))->mustRun();
        $holder->wait();

        $next = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "released";']), 'lock-0001');
        self::assertTrue($next->ready(), $next->message);
        $next->process?->release();
        self::assertSame('released', $next->process?->wait()->output);
    }

    #[After]
    public function removeFixture(): void
    {
        if ($this->fixture === null || ! is_dir($this->fixture)) {
            return;
        }

        foreach (glob($this->fixture.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->fixture);
        $this->fixture = null;
        $this->lockDirectory = null;
    }

    private function posixRunner(int $lockWaitMilliseconds = 500): ControlProcessRunner
    {
        if (DIRECTORY_SEPARATOR !== '/'
            || ! is_file('/usr/bin/flock')
            || ! is_file('/usr/bin/stat')
            || ! is_file('/usr/bin/setsid')
            || ! is_file('/usr/bin/kill')) {
            self::markTestSkipped('The blocked exec/lock contract requires the Linux runtime tools.');
        }

        $this->requireNonPrivilegedIdentity();

        $lock = $this->effectLock($lockWaitMilliseconds);
        $configuration = $this->configuration($lockWaitMilliseconds);

        return new ControlProcessRunner($configuration, $this->redactor(), $lock);
    }

    private function effectLock(int $lockWaitMilliseconds): EffectLock
    {
        if ($this->fixture === null) {
            $this->fixture = sys_get_temp_dir().'/ai6-blocked-process-'.bin2hex(random_bytes(6));
            mkdir($this->fixture, 0700);
        }

        if ($this->lockDirectory === null) {
            $directory = getenv('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY');
            if (! is_string($directory) || $directory === '') {
                self::markTestSkipped('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY is not configured with privileged fixtures.');
            }
            $real = realpath($directory);
            self::assertIsString($real);
            $this->lockDirectory = $real;
        }

        return new EffectLock($this->configuration($lockWaitMilliseconds));
    }

    private function configuration(int $lockWaitMilliseconds, ?string $wrapperScript = null): ProcessConfiguration
    {
        $owner = fileowner($this->lockDirectory);
        self::assertIsInt($owner);

        return new ProcessConfiguration(
            10,
            4096,
            100,
            2,
            $wrapperScript ?? dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            '/usr/bin/dash',
            '/usr/bin/setsid',
            '/usr/bin/kill',
            $this->lockDirectory,
            6,
            $lockWaitMilliseconds,
            $owner,
        );
    }

    /** @param non-empty-list<string> $command
     * @param  array<string, string>  $environment
     */
    private function request(array $command, array $environment = []): ProcessRequest
    {
        return new ProcessRequest(
            $command,
            $this->fixture,
            array_keys($environment),
            $environment,
            new RedactionContext('project-1', 'run-1', 'blocked-process-test'),
        );
    }

    private function requireNonPrivilegedIdentity(): void
    {
        $self = stat('/proc/self');
        self::assertIsArray($self);
        self::assertNotSame(0, $self['uid'], 'The blocked process proof is invalid when run as root.');
    }

    private function redactor(): Redactor
    {
        $ring = new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat('b', 32)],
        ]);

        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 2;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }

        self::assertFileExists($path);
    }

    private function waitForProcessExit(int $processId): void
    {
        $deadline = microtime(true) + 2;
        while ($this->processIsRunning($processId) && microtime(true) < $deadline) {
            usleep(10000);
        }
    }

    private function processIsRunning(int $processId): bool
    {
        $stat = @file_get_contents('/proc/'.$processId.'/stat');
        if (! is_string($stat)) {
            return false;
        }

        $end = strrpos($stat, ')');
        $state = $end === false ? null : substr($stat, $end + 2, 1);

        return $state !== 'Z';
    }
}
