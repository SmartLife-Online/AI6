<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessLimit;
use App\AI6\Shared\Process\ProcessLimits;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\RunningControlProcess;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ControlProcessRunnerTest extends TestCase
{
    public function test_it_uses_argument_lists_and_a_positive_environment_allowlist(): void
    {
        putenv('AI6_PROCESS_BLOCKED=must-not-pass');
        $runner = $this->runner(timeout: 5, outputLimit: 4096);
        $argument = 'literal;$(echo injected)';
        $code = <<<'PHP'
echo $argv[1]."\n";
echo getenv('AI6_PROCESS_ALLOWED')."\n";
echo getenv('AI6_PROCESS_BLOCKED') === false ? 'missing' : 'present';
PHP;

        try {
            $result = $runner->run($this->request(
                [PHP_BINARY, '-r', $code, $argument],
                ['AI6_PROCESS_ALLOWED'],
                ['AI6_PROCESS_ALLOWED' => 'allowed'],
            ));
        } finally {
            putenv('AI6_PROCESS_BLOCKED');
        }

        self::assertSame(ProcessOutcome::SUCCEEDED, $result->outcome);
        self::assertSame($argument."\nallowed\nmissing", $result->output);
    }

    public function test_runtime_and_output_limits_return_named_results_without_partial_output(): void
    {
        $timeout = $this->runner(timeout: 1, outputLimit: 4096)->run($this->request([
            PHP_BINARY,
            '-r',
            'usleep(3000000); echo "late";',
        ]));
        self::assertSame(ProcessOutcome::TIMED_OUT, $timeout->outcome);
        self::assertSame('', $timeout->output);
        self::assertSame(ProcessLimit::RUNTIME_SECONDS, $timeout->limitResult->limit);
        self::assertSame(1, $timeout->limitResult->maximum);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $timeout->limitResult->hash);

        $output = $this->runner(timeout: 5, outputLimit: 64)->run($this->request([
            PHP_BINARY,
            '-r',
            'echo str_repeat("x", 4096);',
        ]));
        self::assertSame(ProcessOutcome::OUTPUT_LIMIT_EXCEEDED, $output->outcome);
        self::assertSame('', $output->output);
        self::assertSame(ProcessLimit::OUTPUT_BYTES, $output->limitResult->limit);
        self::assertSame(64, $output->limitResult->maximum);
        self::assertGreaterThan(64, $output->limitResult->observed);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $output->limitResult->hash);
    }

    public function test_the_posix_wrapper_does_not_add_environment_variables(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The POSIX wrapper environment requires Linux.');
        }
        $result = $this->runner(timeout: 5, outputLimit: 4096)->run($this->request([
            PHP_BINARY, '-r', 'echo json_encode(array_keys(getenv()));',
        ]));
        self::assertSame(ProcessOutcome::SUCCEEDED, $result->outcome);
        self::assertSame([], json_decode($result->output, true, 8, JSON_THROW_ON_ERROR));
    }

    public function test_environment_only_values_cannot_reappear_in_children_or_signal_helpers(): void
    {
        $name = 'AI6_PROCESS_ENV_ONLY';
        self::assertFalse(getenv($name));
        self::assertArrayNotHasKey($name, $_ENV);
        $_ENV[$name] = 'synthetic-must-not-pass';
        try {
            $runner = $this->runner(timeout: 5, outputLimit: 4096);
            $result = $runner->run($this->request([
                PHP_BINARY, '-r', 'echo getenv($argv[1]) === false ? "missing" : "present";', $name,
            ]));
            self::assertSame(ProcessOutcome::SUCCEEDED, $result->outcome);
            self::assertSame('missing', $result->output);
            $helperEnvironment = (new ReflectionMethod($runner, 'clearedEnvironment'))->invoke($runner);
            self::assertArrayHasKey($name, $helperEnvironment);
            self::assertFalse($helperEnvironment[$name]);
        } finally {
            unset($_ENV[$name]);
        }
    }

    public function test_a_running_process_can_be_cancelled_and_errors_are_centrally_redacted(): void
    {
        $runner = $this->runner(timeout: 10, outputLimit: 4096);
        $running = $runner->start($this->request([PHP_BINARY, '-r', 'usleep(5000000);']));
        $running->cancel();
        $cancelled = $running->wait();
        self::assertSame(ProcessOutcome::CANCELLED, $cancelled->outcome);
        self::assertFalse($running->running());

        $failed = $runner->run($this->request([
            PHP_BINARY,
            '-r',
            'fwrite(STDERR, "secret=super-secret"); exit(2);',
        ]));
        self::assertSame(ProcessOutcome::FAILED, $failed->outcome);
        self::assertStringContainsString('[REDACTED:SECRET]', $failed->errorOutput);
        self::assertStringNotContainsString('super-secret', $failed->errorOutput);
    }

    public function test_wait_invokes_a_periodic_heartbeat_while_the_process_is_running(): void
    {
        $running = $this->runner(timeout: 5, outputLimit: 4096)->start($this->request([
            PHP_BINARY,
            '-r',
            'usleep(1300000);',
        ]));
        $heartbeats = 0;

        $result = $running->wait(function () use (&$heartbeats): void {
            $heartbeats++;
        }, 1);

        self::assertTrue($result->succeeded());
        self::assertGreaterThanOrEqual(1, $heartbeats);
    }

    public function test_an_unconfirmed_process_group_termination_falls_back_without_hanging_and_is_named(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! is_file('/usr/bin/true')) {
            self::markTestSkipped('The unconfirmed group-termination proof requires the Linux runtime.');
        }

        $runner = $this->runner(timeout: 10, outputLimit: 4096, killBinary: '/usr/bin/true');
        $running = $runner->start($this->request([PHP_BINARY, '-r', 'usleep(5000000);']));
        $started = microtime(true);
        $running->cancel();
        $result = $running->wait();

        self::assertSame(ProcessOutcome::TERMINATION_FAILED, $result->outcome);
        self::assertFalse($running->running());
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_proc_inventory_distinguishes_an_only_zombie_group_from_a_group_with_a_live_member(): void
    {
        $procRoot = sys_get_temp_dir().'/ai6-proc-inventory-'.bin2hex(random_bytes(8));
        $zombieDirectory = $procRoot.'/1001';
        $liveDirectory = $procRoot.'/1002';
        self::assertTrue(mkdir($zombieDirectory, 0700, true));
        self::assertNotFalse(file_put_contents(
            $zombieDirectory.'/stat',
            '1001 (terminated worker) Z 1 4242 4242 0 -1 0 0 0 0 0 0 0 0 0 0 0 0 0 0',
        ));

        $method = new ReflectionMethod(ControlProcessRunner::class, 'processGroupContainsOnlyZombies');
        $runner = $this->runner(timeout: 5, outputLimit: 4096);

        try {
            self::assertTrue($method->invoke($runner, 4242, $procRoot));

            self::assertTrue(mkdir($liveDirectory, 0700));
            self::assertNotFalse(file_put_contents(
                $liveDirectory.'/stat',
                '1002 (live worker) S 1 4242 4242 0 -1 0 0 0 0 0 0 0 0 0 0 0 0 0 0',
            ));
            self::assertFalse($method->invoke($runner, 4242, $procRoot));
        } finally {
            foreach ([$liveDirectory, $zombieDirectory] as $directory) {
                if (is_file($directory.'/stat')) {
                    unlink($directory.'/stat');
                }
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            if (is_dir($procRoot)) {
                rmdir($procRoot);
            }
        }
    }

    #[DataProvider('completedProcessProvider')]
    public function test_a_completed_direct_process_keeps_its_pid_result_limits_and_redaction(
        string $code,
        int $outputLimit,
        ProcessOutcome $outcome,
        int $exitCode,
        string $output,
        string $error,
    ): void {
        $process = $this->completedDirectProcess([PHP_BINARY, '-r', $code]);
        self::assertFalse($process->isRunning());
        self::assertNull($process->getPid(), 'Exercise adoption only after Symfony has lost its live PID.');
        $runner = $this->runner(timeout: 5, outputLimit: $outputLimit);
        $running = $this->trackCompletedProcess($runner, $process, $outputLimit);

        self::assertGreaterThan(1, $running->processId);
        self::assertStringStartsWith('__AI6_PROCESS_STARTED_V1__:'.$running->processId."\n", $process->getOutput());
        $result = $running->wait();
        self::assertSame($outcome, $result->outcome);
        self::assertSame($exitCode, $result->exitCode);
        self::assertSame($output, $result->output);
        self::assertSame($error, $result->errorOutput);
        self::assertFalse($running->running());
        if ($outcome === ProcessOutcome::OUTPUT_LIMIT_EXCEEDED) {
            self::assertSame(ProcessLimit::OUTPUT_BYTES, $result->limitResult->limit);
            self::assertSame(65, $result->limitResult->observed);
            self::assertSame(64, $result->limitResult->maximum);
        }
    }

    /** @return iterable<string, array{string, int, ProcessOutcome, int, string, string}> */
    public static function completedProcessProvider(): iterable
    {
        yield 'successful exit' => ['echo "payload";', 4096, ProcessOutcome::SUCCEEDED, 0, 'payload', ''];
        yield 'failed exit with redacted error' => ['echo "payload"; fwrite(STDERR, "secret=super-secret"); exit(7);', 4096, ProcessOutcome::FAILED, 7, 'payload', 'secret=[REDACTED:SECRET]'];
        yield 'exact output maximum excludes protocol' => ['echo str_repeat("x", 64);', 64, ProcessOutcome::SUCCEEDED, 0, str_repeat('x', 64), ''];
        yield 'one byte above output maximum' => ['echo str_repeat("x", 65);', 64, ProcessOutcome::OUTPUT_LIMIT_EXCEEDED, 0, '', 'The control process exceeded a resource limit.'];
        yield 'payload cannot replace the wrapper identity' => ['echo "__AI6_PROCESS_STARTED_V1__:9999\n";', 4096, ProcessOutcome::SUCCEEDED, 0, "__AI6_PROCESS_STARTED_V1__:9999\n", ''];
    }

    #[DataProvider('missingProcessIdentityProvider')]
    public function test_a_completed_process_without_a_valid_identity_still_fails_closed(string $output): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The direct wrapper identity requires Linux.');
        }
        // Deliberately omit the trusted wrapper to exercise its failed protocol.
        $process = new Process([PHP_BINARY, '-r', 'echo $argv[1];', $output]);
        self::assertSame(0, $process->run());
        self::assertNull($process->getPid());
        $this->expectException(RuntimeException::class);
        $this->trackCompletedProcess($this->runner(5, 4096), $process, 4096);
    }

    /** @return iterable<string, array{string}> */
    public static function missingProcessIdentityProvider(): iterable
    {
        yield 'missing' => [''];
        yield 'zero' => ["__AI6_PROCESS_STARTED_V1__:0\n"];
        yield 'negative' => ["__AI6_PROCESS_STARTED_V1__:-1\n"];
        yield 'integer overflow' => ["__AI6_PROCESS_STARTED_V1__:9999999999999999999\n"];
    }

    #[DataProvider('orphanedProcessGroupProvider')]
    public function test_a_completed_parent_keeps_background_children_subject_to_group_limits_and_termination(int $children, bool $cancel): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('The orphaned process-group proof requires Linux with pcntl.');
        }
        $directory = sys_get_temp_dir().'/ai6-orphan-process-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $code = <<<'PHP'
file_put_contents($argv[1].'/parent', (string) getmypid());
for ($i = 0; $i < (int) $argv[2]; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) { exit(3); }
    if ($pid === 0) {
        fclose(STDOUT);
        fclose(STDERR);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        file_put_contents($argv[1].'/child-'.$i, (string) getmypid());
        while (true) { usleep(10000); }
    }
}
$deadline = microtime(true) + 5;
while (count(glob($argv[1].'/child-*')) !== (int) $argv[2]) {
    if (microtime(true) >= $deadline) { exit(4); }
    usleep(1000);
}
echo 'parent-completed';
PHP;
        try {
            $process = $this->completedDirectProcess([PHP_BINARY, '-r', $code, $directory, (string) $children]);
            $parentPid = (int) file_get_contents($directory.'/parent');
            self::assertSame(0, $process->getExitCode());
            self::assertNull($process->getPid());
            $runner = $this->runner(5, 4096);
            $limits = new ProcessLimits(5, 4096, 1, 10, 1024, 10);
            $running = $this->trackCompletedProcess($runner, $process, 4096, $limits);
            self::assertSame($parentPid, $running->processId, 'The exec target must keep the wrapper/group PID.');
            self::assertTrue($running->running(), 'Children remain supervised after the parent has exited.');
            $childPids = array_map(static fn (string $path): int => (int) file_get_contents($path), glob($directory.'/child-*'));
            self::assertCount($children, $childPids);
            foreach ($childPids as $childPid) {
                self::assertTrue($this->liveProcess($childPid));
            }
            if ($cancel) {
                $running->cancel();
            }
            $result = $running->wait();
            self::assertSame($cancel ? ProcessOutcome::CANCELLED : ProcessOutcome::RESOURCE_LIMIT_EXCEEDED, $result->outcome);
            self::assertSame(0, $result->exitCode, 'The exited parent must not hide its surviving group.');
            self::assertSame('', $result->output);
            if (! $cancel) {
                self::assertSame(ProcessLimit::PROCESS_COUNT, $result->limitResult->limit);
                self::assertSame(1, $result->limitResult->maximum);
                self::assertSame($children, $result->limitResult->observed);
            }
            foreach ($childPids as $childPid) {
                self::assertFalse($this->liveProcess($childPid), 'The TERM-ignoring child must be killed with its group.');
            }
            self::assertFalse($running->running());
        } finally {
            // Also clean up when the regression deliberately fails on old code.
            if (is_file($directory.'/parent')) {
                $parentPid = (int) file_get_contents($directory.'/parent');
                if ($parentPid > 1) {
                    (new Process(['/usr/bin/kill', '-KILL', '--', '-'.$parentPid]))->run();
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function orphanedProcessGroupProvider(): iterable
    {
        yield 'two surviving children exceed one process' => [2, false];
        yield 'one surviving child is cancelled as a group' => [1, true];
    }

    private function liveProcess(int $pid): bool
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        $end = is_string($stat) ? strrpos($stat, ')') : false;

        return $end !== false && ! in_array(substr($stat, $end + 2, 1), ['Z', 'X'], true);
    }

    /** @param non-empty-list<string> $command */
    private function completedDirectProcess(array $command): Process
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The direct wrapper identity requires Linux.');
        }
        $process = new Process([
            '/usr/bin/setsid', '--', '/usr/bin/dash',
            dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh', 'direct', '--', ...$command,
        ]);
        $process->run();

        return $process;
    }

    private function trackCompletedProcess(ControlProcessRunner $runner, Process $process, int $outputLimit, ?ProcessLimits $limits = null): RunningControlProcess
    {
        return (new ReflectionMethod($runner, 'trackStartedProcess'))->invoke(
            $runner, $process, $this->request(['/usr/bin/true']), [5, $outputLimit, 100, $limits],
        );
    }

    private function runner(int $timeout, int $outputLimit, ?string $killBinary = null): ControlProcessRunner
    {
        $posix = DIRECTORY_SEPARATOR === '/';
        $configuration = new ProcessConfiguration(
            $timeout,
            $outputLimit,
            100,
            2,
            dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            $posix ? '/usr/bin/dash' : '/bin/sh',
            $posix ? '/usr/bin/setsid' : null,
            $posix ? ($killBinary ?? '/usr/bin/kill') : null,
            dirname(__DIR__, 4).'/storage/framework/testing/missing-locks',
            1,
            100,
            0,
        );
        $redactor = $this->redactor();

        return new ControlProcessRunner($configuration, $redactor, new EffectLock($configuration));
    }

    /** @param non-empty-list<string> $command
     * @param  list<string>  $allowlist
     * @param  array<string, string>  $environment
     */
    private function request(array $command, array $allowlist = [], array $environment = []): ProcessRequest
    {
        return new ProcessRequest(
            $command,
            dirname(__DIR__, 4),
            $allowlist,
            $environment,
            new RedactionContext('project-1', 'run-1', 'process-test'),
        );
    }

    private function redactor(): Redactor
    {
        $ring = new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat('k', 32)],
        ]);

        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );
    }
}
