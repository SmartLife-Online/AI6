<?php

namespace Tests\Unit\Shared\Process;

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
use PHPUnit\Framework\TestCase;

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

        $output = $this->runner(timeout: 5, outputLimit: 64)->run($this->request([
            PHP_BINARY,
            '-r',
            'echo str_repeat("x", 4096);',
        ]));
        self::assertSame(ProcessOutcome::OUTPUT_LIMIT_EXCEEDED, $output->outcome);
        self::assertSame('', $output->output);
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
