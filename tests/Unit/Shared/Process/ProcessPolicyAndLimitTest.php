<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessLimit;
use App\AI6\Shared\Process\ProcessLimits;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\TestCase;

final class ProcessPolicyAndLimitTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-process-policy-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_server_limits_clamp_approval_and_one_file_over_returns_a_hash_bound_result(): void
    {
        $server = new ProcessLimits(10, 4096, 4, 1, 128, 1);
        $runner = $this->runner($server, true);

        $result = $runner->run($this->request(
            'file_put_contents($argv[1]."/one", "a"); file_put_contents($argv[1]."/two", "b");',
            approvedLimits: new ProcessLimits(20, 8192, 8, 2, 256, 2),
        ));
        self::assertSame(ProcessOutcome::RESOURCE_LIMIT_EXCEEDED, $result->outcome);
        self::assertSame(ProcessLimit::FILE_COUNT, $result->limitResult->limit);
        self::assertSame(2, $result->limitResult->observed);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $result->limitResult->hash);
        self::assertSame('', $result->output);
    }

    public function test_runtime_and_output_limits_accept_the_maximum_and_clamp_one_over_approvals(): void
    {
        $approval = new ProcessLimits(30, 128, 8, 20, 2048, 20);

        // The runtime ceiling gets its own runner: the interpreter start of the
        // accepted run must stay far below the server limit even under full suite
        // load, while the rejected run overshoots it by a wide margin. A limit close
        // to the process start overhead would make this proof depend on wall clock.
        $runtime = $this->runner(new ProcessLimits(5, 1024, 4, 10, 1024, 10), true);
        self::assertSame(ProcessOutcome::SUCCEEDED, $runtime->run($this->request('usleep(100000);', approvedLimits: $approval))->outcome);
        $timeout = $runtime->run($this->request('usleep(15000000);', approvedLimits: $approval));
        self::assertSame(ProcessLimit::RUNTIME_SECONDS, $timeout->limitResult->limit);
        self::assertSame(5, $timeout->limitResult->maximum);

        $bytes = $this->runner(new ProcessLimits(30, 64, 4, 10, 1024, 10), true);
        self::assertSame(ProcessOutcome::SUCCEEDED, $bytes->run($this->request('echo str_repeat("x", 64);', approvedLimits: $approval))->outcome);
        $output = $bytes->run($this->request('echo str_repeat("x", 65);', approvedLimits: $approval));
        self::assertSame(ProcessLimit::OUTPUT_BYTES, $output->limitResult->limit);
        self::assertSame(64, $output->limitResult->maximum);
    }

    public function test_process_count_accepts_one_process_and_rejects_one_over_on_linux(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('proc_open')) {
            self::markTestSkipped('The real process-group PID limit proof requires Linux.');
        }
        $limits = new ProcessLimits(5, 4096, 1, 10, 1024, 10);
        self::assertSame(ProcessOutcome::SUCCEEDED, $this->runner($limits, true)->run($this->request('usleep(200000);'))->outcome);

        $code = '$child=proc_open([PHP_BINARY,"-r","usleep(3000000);"],[], $pipes); usleep(3000000);';
        $result = $this->runner($limits, true)->run($this->request($code));
        self::assertSame(ProcessLimit::PROCESS_COUNT, $result->limitResult->limit);
        self::assertSame(1, $result->limitResult->maximum);
        self::assertGreaterThan(1, $result->limitResult->observed);
    }

    public function test_file_byte_and_artifact_limits_accept_the_maximum_and_reject_one_over(): void
    {
        $limits = new ProcessLimits(10, 4096, 4, 2, 2, 1);
        $atMaximum = $this->runner($limits, true)->run($this->request(
            'file_put_contents($argv[1]."/one", "a"); file_put_contents($argv[1]."/two", "b"); file_put_contents($argv[2]."/artifact", "a");',
            resultDirectory: $this->root.'/results-max',
            artifactDirectory: $this->root.'/artifacts-max',
        ));
        self::assertSame(ProcessOutcome::SUCCEEDED, $atMaximum->outcome);

        $fileLimit = $this->runner($limits, true)->run($this->request(
            'file_put_contents($argv[1]."/one", "a"); file_put_contents($argv[1]."/two", "b"); file_put_contents($argv[1]."/three", "c");',
            resultDirectory: $this->root.'/results-files',
            artifactDirectory: $this->root.'/artifacts-files',
        ));
        self::assertSame(ProcessLimit::FILE_COUNT, $fileLimit->limitResult->limit);

        $byteLimit = $this->runner($limits, true)->run($this->request(
            'file_put_contents($argv[1]."/one", "abc");',
            resultDirectory: $this->root.'/results-bytes',
            artifactDirectory: $this->root.'/artifacts-bytes',
        ));
        self::assertSame(ProcessLimit::TOTAL_BYTES, $byteLimit->limitResult->limit);

        $artifactLimit = $this->runner($limits, true)->run($this->request(
            'file_put_contents($argv[2]."/one", "a"); file_put_contents($argv[2]."/two", "b");',
            resultDirectory: $this->root.'/results-artifacts',
            artifactDirectory: $this->root.'/artifacts-artifacts',
        ));
        self::assertSame(ProcessLimit::ARTIFACT_COUNT, $artifactLimit->limitResult->limit);
    }

    public function test_missing_server_verified_isolation_boundary_fails_before_spawn_and_is_named_by_run(): void
    {
        $request = $this->request('file_put_contents($argv[1]."/spawned", "yes");');
        try {
            $runner = $this->runner(new ProcessLimits(10, 4096, 4, 10, 1024, 10), false);
            try {
                $runner->start($request);
                self::fail('The direct start must reject a missing isolation verifier.');
            } catch (ProcessStartRejectedException $exception) {
                self::assertSame('The required process isolation boundary is not available.', $exception->getMessage());
            }
            $result = $runner->run($request);
            self::assertSame(ProcessOutcome::START_REJECTED, $result->outcome);
            self::assertSame('The required process isolation boundary is not available.', $result->errorOutput);
        } finally {
            self::assertFileDoesNotExist($this->root.'/spawned');
        }
    }

    private function runner(ProcessLimits $limits, bool $isolated): ControlProcessRunner
    {
        $configuration = new ProcessConfiguration(10, 4096, 10, 2, dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh', '/bin/sh', null, null, $this->root.'/locks', 1, 10, 0);
        $policy = new ProcessPolicy(ProcessPolicyName::AGENT, 10, 4096, [PHP_BINARY], [], [$this->root], false, 10);
        $control = new ProcessPolicy(ProcessPolicyName::CONTROL, 10, 4096, [PHP_BINARY], [], [$this->root], false, 10);
        $checker = new ProcessPolicy(ProcessPolicyName::CHECKER, 10, 4096, [PHP_BINARY], [], [$this->root], false, 10);
        $registry = new ProcessPolicyRegistry(['control' => $control, 'agent' => $policy, 'checker' => $checker], $limits);

        $boundary = $isolated ? new class implements ProcessIsolationBoundary
        {
            public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void {}
        } : null;

        return new ControlProcessRunner($configuration, $this->redactor(), new EffectLock($configuration), $registry, $boundary);
    }

    private function request(
        string $code,
        ?string $resultDirectory = null,
        ?string $artifactDirectory = null,
        ?ProcessLimits $approvedLimits = null,
    ): ProcessRequest {
        $resultDirectory ??= $this->root;
        $artifactDirectory ??= $this->root;
        foreach ([$resultDirectory, $artifactDirectory] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
        }

        return new ProcessRequest([PHP_BINARY, '-r', $code, $resultDirectory, $artifactDirectory], $this->root, [], [], new RedactionContext('project-1', 'run-1', 'limit-test'), policy: ProcessPolicyName::AGENT, approvedLimits: $approvedLimits, resultDirectory: $resultDirectory, artifactDirectory: $artifactDirectory);
    }

    private function redactor(): Redactor
    {
        return new Redactor(new RedactionPolicy(RedactionRuleSet::defaults()), new RedactionFingerprintGenerator(new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('k', 32)]])));
    }
}
