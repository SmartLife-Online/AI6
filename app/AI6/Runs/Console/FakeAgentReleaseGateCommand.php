<?php

namespace App\AI6\Runs\Console;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

final class FakeAgentReleaseGateCommand extends Command
{
    public const SKIPPED = 2;

    /** Known contract gaps remain blocking even when every selected test passes. */
    public const AC_COVERAGE_GAPS = [
        'AC-02' => 'Die quellgebundene Schreibstelleninventur umfasst die Release-Testklassen und ihre geerbten Fixturehelfer; Einträge mit requires_service sind noch auf öffentliche Producer umzustellen und gelten ausdrücklich nicht als zulässige Ausnahmen.',
        'AC-04' => 'Gitmetadaten, Hook, Hostinstruktion und Credentials sind für die Agentenrolle nachgewiesen; die Wirkungslosigkeit providerspezifischer Konfiguration im geprüften Baum nicht: RunImplementation übergibt den Export direkt und durchläuft keine ExecutionHomeManager-Versiegelung wie die Reviewpfade.',
    ];

    /** @var array<string, string> */
    public const TEST_ENVIRONMENT = [
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_MAINTENANCE_DRIVER' => 'file',
        'CACHE_STORE' => 'array',
        'LOG_CHANNEL' => 'null',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ];

    /** @var list<string> */
    public const TEST_PATHS = [
        'tests/Feature/Runs/FakeAgentReleaseGateContractTest.php',
        'tests/Feature/Runs/ImplementationTurnTest.php',
        'tests/Feature/Runs/ReviewOnlyExecutionTest.php',
        'tests/Feature/Runs/ReviewOnlyRunContractTest.php',
        'tests/Feature/Runs/HumanRequestResumeTest.php',
        'tests/Feature/Runs/WaitResolverMatrixTest.php',
        'tests/Feature/Runs/ContractChangeTest.php',
        'tests/Feature/Runs/ExecutionJobContractTest.php',
        'tests/Feature/Runs/CheckStepTest.php',
        'tests/Feature/Runs/RunRoundLimitGateTest.php',
        'tests/Feature/Runs/RunRuntimeLimitGateTest.php',
        'tests/Feature/Runs/ImplementationLimitTurnTest.php',
        'tests/Feature/Runs/ScopeDecisionTest.php',
        'tests/Feature/Runs/TicketApprovalQueueTest.php',
        'tests/Feature/Runs/PublishCandidateGateTest.php',
        'tests/Feature/Runs/RunFinalizationStepTest.php',
        'tests/Feature/Runs/ReviewStallResumeTest.php',
        'tests/Feature/Runs/RunInterventionLimitTest.php',
        'tests/Feature/Runs/EvidenceInvalidationTest.php',
        'tests/Feature/Runs/ProjectQueueStartFeatureTest.php',
        'tests/Feature/Runs/QueueSessionIsolationTest.php',
        'tests/Feature/Runs/RunRetentionSweepTest.php',
        'tests/Feature/Runs/RunRetentionQueueRedeliveryTest.php',
        'tests/Feature/Reviews/ReviewRoundTest.php',
        'tests/Feature/Reviews/ReReviewCompletenessTest.php',
        'tests/Feature/Reviews/SecurityReviewIsolationTest.php',
        'tests/Feature/HumanLoop/HumanRequestAnswerTest.php',
        'tests/Feature/HumanLoop/HumanRequestNotificationTest.php',
        'tests/Feature/HumanLoop/CandidateGateInteractionTest.php',
        'tests/Feature/HumanLoop/ScopeApprovalTest.php',
        'tests/Feature/Git/PublishCandidateTest.php',
        'tests/Feature/Git/HardenedGitRunnerTest.php',
        'tests/Feature/Git/ImplementationImportIsolationTest.php',
        'tests/Feature/Git/SecurityReviewCandidateIsolationTest.php',
        'tests/Feature/Git/ControlOperationCrashInjectionTest.php',
        'tests/Feature/Git/ControlOperationReconcilerTest.php',
        'tests/Feature/Git/TicketMutationExecutorTest.php',
        'tests/Feature/Git/RunCancellationExecutorTest.php',
        'tests/Feature/Git/ReportOnlyCompletionExecutorTest.php',
        'tests/Feature/Git/ContractAmendmentTest.php',
        'tests/Feature/Checks/CheckIsolationTest.php',
        'tests/Feature/Agents/ImplementationInstructionTest.php',
        'tests/Feature/Shared/Security/SecurityBootstrapTest.php',
        'tests/Feature/Projects/ControlOperationAuthorizationTest.php',
        'tests/Unit/Runs/ReleaseGateCommandTest.php',
        'tests/Unit/Runs/CandidateGateTest.php',
        'tests/Unit/Runs/RunLimitPolicyTest.php',
        'tests/Unit/Runs/ApprovalLimitsTest.php',
        'tests/Unit/Runs/WaitReasonRegistryTest.php',
        'tests/Unit/Agents/ExecutionHomeManagerTest.php',
        'tests/Unit/Agents/AgentResultValidatorTest.php',
        'tests/Unit/Git/GitHardeningContractTest.php',
    ];

    protected $signature = 'ai6:release-gate';

    protected $description = 'Führt den vollständigen deterministischen FakeAgent-Workflow- und Recovery-Nachweis aus.';

    public function handle(): int
    {
        $environment = [];
        foreach (['PATH', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TMP', 'TEMP', 'LANG', 'LC_ALL'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        // Fixed server-owned PHP bootstrap, never repository/provider input.
        // Test variables are established inside the cleared child environment,
        // before Artisan boots, without widening the control-policy allowlist.
        $bootstrap = '$environment = '.var_export(self::TEST_ENVIRONMENT, true).';'
            .'foreach ($environment as $name => $value) { putenv($name."=".$value); $_ENV[$name] = $_SERVER[$name] = $value; }'
            .'array_shift($argv); $argc = count($argv); $_SERVER["argv"] = $argv; $_SERVER["argc"] = $argc; require $argv[0];';
        $skipped = 0;
        $executed = 0;
        $failure = null;
        $emptySelection = false;
        $selections = self::testSelections();
        foreach ($selections as [$path, $filter]) {
            $command = [
                PHP_BINARY, '-r', $bootstrap, base_path('artisan'),
                'test', '--without-tty', '--no-ansi',
                '--filter='.$filter,
            ];
            $result = $this->laravel->make(ControlProcessRunner::class)->run(new ProcessRequest(
                $command,
                base_path(),
                array_keys($environment),
                $environment,
                new RedactionContext('release-gate', null, $path),
            ));
            $output = trim($result->output."\n".$result->errorOutput);
            if ($output !== '') {
                $this->line($output);
            }
            $skipped += self::skippedCount($output);
            $count = self::executedCount($output);
            $executed += $count;
            $emptySelection = $emptySelection || ($count === 0 && self::skippedCount($output) === 0);
            if (! $result->succeeded()) {
                $failure ??= $result->exitCode ?: self::FAILURE;
                $this->line('FEHLER '.$path.': '.$result->outcome->value);
            }
        }

        foreach (self::AC_COVERAGE_GAPS as $criterion => $gap) {
            $this->line('OFFEN '.$criterion.': '.$gap);
        }

        if ($failure !== null) {
            $this->line(sprintf(
                'FEHLER: FakeAgent-Release-Gate fehlgeschlagen (Exitcode %d).',
                $failure,
            ));

            return $failure;
        }

        if ($skipped > 0) {
            $this->line(sprintf('%d Tests bestanden.', $executed));
            $this->line(sprintf('%d Nachweis(e) ÜBERSPRUNGEN.', $skipped));
            $this->line('Release-Gate nicht bestanden.');

            return self::SKIPPED;
        }

        $minimum = count($selections);
        if ($emptySelection || $executed < $minimum) {
            $this->line(sprintf(
                'FEHLER: FakeAgent-Release-Gate führte nur %d Test(s) für %d gebundene Testauswahlen aus.',
                $executed,
                $minimum,
            ));

            return self::FAILURE;
        }

        return $this->completeEvidence($executed, self::AC_COVERAGE_GAPS);
    }

    /** @param array<string, string> $gaps */
    private function completeEvidence(int $executed, array $gaps): int
    {
        if ($gaps !== []) {
            $this->line('Release-Gate nicht bestanden: unvollständige AC-Nachweise.');

            return self::FAILURE;
        }

        $this->line(sprintf('%d Tests bestanden.', $executed));
        $this->line('FakeAgent-Release-Gate vollständig bestanden.');
        $this->line('Übersprungene Nachweise: 0.');

        return self::SUCCESS;
    }

    /** @return list<array{string, string}> */
    public static function testSelections(): array
    {
        $selections = [];
        foreach (self::TEST_PATHS as $path) {
            $prefix = '/(?:^|\\\\)'.preg_quote(pathinfo($path, PATHINFO_FILENAME)).'::';
            if ($path !== 'tests/Feature/Runs/RunFinalizationStepTest.php') {
                $selections[] = [$path, $prefix.'/'];

                continue;
            }

            // This long-running class uses public test_* methods exclusively.
            // Discover all of them so batching cannot silently omit a new case.
            $class = new ReflectionClass('Tests\\Feature\\Runs\\RunFinalizationStepTest');
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->getName(), 'test_')) {
                    $selections[] = [$path, $prefix.preg_quote($method->getName()).'(?:$| with data set )/'];
                }
            }
        }

        return $selections;
    }

    private static function skippedCount(string $output): int
    {
        $counts = [0];

        foreach (['/\bSkipped:\s*(\d+)/i', '/\b(\d+)\s+skipped\b/i'] as $pattern) {
            if (preg_match_all($pattern, $output, $matches) > 0) {
                array_push($counts, ...array_map('intval', $matches[1]));
            }
        }

        return max($counts);
    }

    private static function executedCount(string $output): int
    {
        if (preg_match('/\b(\d+)\s+passed\b/i', $output, $matches) === 1) {
            return (int) $matches[1];
        }
        if (preg_match('/\bTests:\s*(\d+)\s*(?:,|$)/im', $output, $matches) === 1) {
            return max(0, (int) $matches[1] - self::skippedCount($output));
        }

        return 0;
    }
}
