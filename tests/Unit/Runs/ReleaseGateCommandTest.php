<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\Console\FakeAgentReleaseGateCommand;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Runs\RunFinalizationStepTest;
use Tests\TestCase;

final class ReleaseGateCommandTest extends TestCase
{
    /** @var list<ProcessRequest> */
    private array $requests = [];

    public function test_the_registered_command_runs_exactly_the_bound_release_suite(): void
    {
        $this->fakeResults();
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('unvollständige AC-Nachweise', Artisan::output());
        self::assertCount(count(FakeAgentReleaseGateCommand::testSelections()), $this->requests);
        foreach ($this->requests as $index => $request) {
            [$path, $filter] = FakeAgentReleaseGateCommand::testSelections()[$index];
            self::assertSame([PHP_BINARY, '-r'], array_slice($request->command, 0, 2));
            self::assertSame([
                base_path('artisan'), 'test', '--without-tty', '--no-ansi',
                '--filter='.$filter,
            ], array_slice($request->command, 3));
            self::assertSame(base_path(), $request->workingDirectory);
            self::assertSame($path, $request->redactionContext->identifier);
            self::assertSame(array_keys($request->environment), $request->environmentAllowlist);
            self::assertNull($request->timeoutSeconds, 'The central configured timeout remains authoritative.');
            self::assertSame([], array_intersect(array_keys(FakeAgentReleaseGateCommand::TEST_ENVIRONMENT), $request->environmentAllowlist));
        }
    }

    public function test_every_phpunit_failure_is_returned_as_a_non_zero_exit_code(): void
    {
        $this->fakeResults("Tests: 1, Assertions: 1, Failures: 1.\n", ProcessOutcome::FAILED, 7);
        self::assertSame(7, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('fehlgeschlagen (Exitcode 7)', Artisan::output());
        self::assertCount(count(FakeAgentReleaseGateCommand::testSelections()), $this->requests);
    }

    public function test_a_rejected_central_process_start_fails_without_a_success_exit_code(): void
    {
        $this->fakeResults('', ProcessOutcome::START_REJECTED, null);
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('start_rejected', Artisan::output());
    }

    public function test_green_tests_cannot_close_known_acceptance_coverage_gaps(): void
    {
        $this->fakeResults();
        self::assertNotEmpty(FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS);
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        $output = Artisan::output();
        foreach (FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS as $criterion => $reason) {
            self::assertStringContainsString('OFFEN '.$criterion.': '.$reason, $output);
        }
        self::assertStringNotContainsString('vollständig bestanden', $output);
    }

    public function test_complete_evidence_has_a_success_path_without_a_runtime_bypass_for_open_gaps(): void
    {
        $command = $this->app->make(FakeAgentReleaseGateCommand::class);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $completion = new \ReflectionMethod($command, 'completeEvidence');
        self::assertSame(0, $completion->invoke($command, 123, []));
        self::assertSame("123 Tests bestanden.\nFakeAgent-Release-Gate vollständig bestanden.\nÜbersprungene Nachweise: 0.\n", str_replace("\r\n", "\n", $output->fetch()));
        self::assertSame(1, $completion->invoke($command, 123, ['AC-04' => 'Offener Nachweis.']));
        self::assertSame("Release-Gate nicht bestanden: unvollständige AC-Nachweise.\n", str_replace("\r\n", "\n", $output->fetch()));
    }

    public function test_skipped_evidence_is_named_and_never_reported_as_passed(): void
    {
        $this->fakeResults("Tests: 6, Assertions: 1, Skipped: 5.\n");
        self::assertSame(FakeAgentReleaseGateCommand::SKIPPED, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('5 Nachweis(e) ÜBERSPRUNGEN', Artisan::output());
    }

    public function test_collision_style_skipped_evidence_is_named_and_never_reported_as_passed(): void
    {
        $this->fakeResults("Tests: 5 skipped, 1 passed (1 assertions)\n");
        self::assertSame(FakeAgentReleaseGateCommand::SKIPPED, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('5 Nachweis(e) ÜBERSPRUNGEN', Artisan::output());
    }

    public function test_an_empty_phpunit_selection_fails_the_gate(): void
    {
        $this->fakeResults("No tests found.\n", repeat: true);
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('nur 0 Test(s)', Artisan::output());
    }

    public function test_fewer_tests_than_bound_classes_fail_the_gate(): void
    {
        $this->fakeResults("No tests found.\n");
        $underCoverage = count(FakeAgentReleaseGateCommand::testSelections()) - 1;
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString("nur {$underCoverage} Test(s)", Artisan::output());
    }

    public function test_one_empty_class_cannot_be_hidden_by_many_tests_in_other_classes(): void
    {
        $this->fakeResults("Tests: 100 passed (200 assertions)\n", repeat: true, emptyLast: true);
        self::assertSame(1, Artisan::call('ai6:release-gate'));
        self::assertStringContainsString('führte nur', Artisan::output());
    }

    public function test_the_filter_matches_complete_class_segments_only(): void
    {
        $this->fakeResults();
        Artisan::call('ai6:release-gate');
        foreach ($this->requests as $request) {
            if ($request->redactionContext->identifier !== 'tests/Unit/Runs/CandidateGateTest.php') {
                continue;
            }
            $filter = substr($request->command[array_key_last($request->command)], strlen('--filter='));
            self::assertSame(1, preg_match($filter, 'Tests\\Unit\\Runs\\CandidateGateTest::test_it'));
            self::assertSame(0, preg_match($filter, 'Tests\\Feature\\Runs\\PublishCandidateGateTest::test_it'));

            return;
        }
        self::fail('The candidate gate class was not executed.');
    }

    public function test_the_real_central_runner_boots_the_child_with_test_values_and_no_parent_secret(): void
    {
        $runner = $this->app->make(ControlProcessRunner::class);
        $this->fakeResults();
        Artisan::call('ai6:release-gate');
        $request = $this->requests[0];
        $script = tempnam(sys_get_temp_dir(), 'ai6-release-bootstrap-');
        self::assertIsString($script);
        $previous = getenv('AI6_RELEASE_GATE_CANARY');
        putenv('AI6_RELEASE_GATE_CANARY=private-parent-value');
        try {
            file_put_contents($script, '<?php echo json_encode(["environment" => getenv("APP_ENV"), "database" => getenv("DB_DATABASE"), "secret" => getenv("AI6_RELEASE_GATE_CANARY"), "arguments" => array_slice($_SERVER["argv"], 1)]); exit(7);');
            $command = $request->command;
            $command[3] = $script;
            $result = $runner->run(new ProcessRequest($command, $request->workingDirectory, $request->environmentAllowlist, $request->environment, $request->redactionContext));
            self::assertSame(ProcessOutcome::FAILED, $result->outcome, $result->errorOutput);
            self::assertSame(7, $result->exitCode);
            self::assertSame([
                'environment' => 'testing',
                'database' => ':memory:',
                'secret' => false,
                'arguments' => array_slice($command, 4),
            ], json_decode($result->output, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($script);
            putenv(is_string($previous) ? 'AI6_RELEASE_GATE_CANARY='.$previous : 'AI6_RELEASE_GATE_CANARY');
        }
    }

    public function test_finalization_batches_include_every_test_and_dataset_exactly_once(): void
    {
        $filters = array_column(array_filter(
            FakeAgentReleaseGateCommand::testSelections(),
            static fn (array $selection): bool => $selection[0] === 'tests/Feature/Runs/RunFinalizationStepTest.php',
        ), 1);
        self::assertGreaterThan(1, count($filters));
        $class = new \ReflectionClass(RunFinalizationStepTest::class);
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with($method->getName(), 'test_')) {
                self::assertSame([], $method->getAttributes(Test::class), 'A new attribute-only test must be included in batch discovery.');

                continue;
            }
            foreach (['', ' with data set "example"'] as $suffix) {
                $name = $class->getName().'::'.$method->getName().$suffix;
                self::assertSame(1, array_sum(array_map(static fn (string $filter): int => preg_match($filter, $name), $filters)), $name);
            }
        }
    }

    private function fakeResults(
        string $output = "Tests: 1 passed (1 assertions)\n",
        ProcessOutcome $outcome = ProcessOutcome::SUCCEEDED,
        ?int $exitCode = 0,
        bool $repeat = false,
        bool $emptyLast = false,
    ): void {
        $record = function (ProcessRequest $request) use ($output, $outcome, $exitCode, $repeat, $emptyLast): ProcessResult {
            $this->requests[] = $request;
            $number = count($this->requests);
            if ($emptyLast && $number === count(FakeAgentReleaseGateCommand::testSelections())) {
                return new ProcessResult(ProcessOutcome::SUCCEEDED, 0, 'No tests found.', '', 0);
            }

            return $number === 1 || $repeat
                ? new ProcessResult($outcome, $exitCode, $output, '', 0)
                : new ProcessResult(ProcessOutcome::SUCCEEDED, 0, "Tests: 1 passed (1 assertions)\n", '', 0);
        };
        // Only the unit-level transport response is substituted. The test above
        // executes the captured request through the real, unchanged runner.
        $this->app->instance(ControlProcessRunner::class, new class($record)
        {
            public function __construct(private Closure $record) {}

            public function run(ProcessRequest $request): ProcessResult
            {
                return ($this->record)($request);
            }
        });
    }
}
