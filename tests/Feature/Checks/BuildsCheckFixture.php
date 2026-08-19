<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckProfileAllowlist;
use App\AI6\Checks\CheckProfileRegistry;
use App\AI6\Checks\CheckRunner;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\ProjectConfigurationParser;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;

/**
 * A run with a real worktree plus the trusted check runtime around it.
 *
 * The scripts the probe profiles execute are ordinary files inside the checked
 * tree; no profile ever receives inline code, which the registry rejects.
 */
trait BuildsCheckFixture
{
    use BuildsImplementationTurnFixture;

    /** @return array{run: Run, worktree: string} */
    protected function checkableRun(string $ticketId): array
    {
        $fixture = $this->preparedImplementationRun($ticketId);
        $worktree = $fixture['worktree'];

        // Ordinary script files inside the checked tree. Their content is what
        // makes a probe succeed, fail, hang or mutate.
        $this->writeProbeScript($worktree, 'ai6-check-ok.php', "<?php\n\nfwrite(STDOUT, \"check ok\\n\");\nexit(0);\n");
        $this->writeProbeScript($worktree, 'ai6-check-fail.php', "<?php\n\nfwrite(STDERR, \"check failed\\n\");\nexit(1);\n");
        $this->writeProbeScript($worktree, 'ai6-check-slow.php', "<?php\n\nsleep(30);\nexit(0);\n");
        $this->writeProbeScript($worktree, 'ai6-check-mutate.php', "<?php\n\nfile_put_contents(__DIR__ . '/ai6-check-mutation.txt', \"mutated\\n\");\nexit(0);\n");

        return ['run' => $fixture['run'], 'worktree' => $worktree];
    }

    /**
     * Bind the trusted check runtime: mailbox roots, policy roots and profiles.
     *
     * @param  array<string, array<string, mixed>>  $profiles
     */
    protected function bindCheckRuntime(array $profiles, int $timeoutSeconds = 120): void
    {
        $executionRoot = $this->implementationTemp('checker-executions');
        $outputRoot = $this->implementationTemp('checker-outputs');

        // The trusted server defaults may only name profiles the registry knows
        // and require a non-empty list per phase, so the phase this fixture does
        // not drive gets its own declared profile.
        $firstProfile = (string) array_key_first($profiles);
        $profiles['probe-final'] = $this->probeProfile(['ai6-check-ok.php'], phases: ['final']);

        config([
            'ai6.execution_mailboxes.checker_root' => $executionRoot,
            'ai6.execution_mailboxes.checker_output_root' => $outputRoot,
            'ai6.process.policies.checker.working_roots' => [$executionRoot],
            'ai6.process.policies.checker.timeout_seconds' => $timeoutSeconds,
            'ai6.process.policies.checker.allowed_executables' => $this->probeExecutables(),
            'ai6.checks.profiles' => $profiles,
        ]);
        if (DIRECTORY_SEPARATOR !== '/') {
            // A POSIX process group is unavailable here; the containment
            // boundary still applies unchanged.
            config(['ai6.process.policies.checker.requires_process_group' => false]);
        }

        config([
            'ai6.project_config.server_defaults.checks.before_review' => [$firstProfile],
            'ai6.project_config.server_defaults.checks.final' => ['probe-final'],
        ]);

        // No isolation substitution: checks run through the shipped
        // DelegatingProcessIsolationBoundary, which routes the checker policy
        // to the check containment boundary outside the checker role.
        //
        // Executing a check outside the checker role is refused while the
        // checker isolation control is active. The test runtime is not that
        // role, so it takes the same visible, trusted reduction an operator
        // would have to take — never a substituted seam.
        $this->reduceCheckerIsolation();

        foreach ([
            ProcessPolicyRegistry::class,
            ControlProcessRunner::class,
            CheckProfileRegistry::class,
            CheckProfileAllowlist::class,
            CheckRunner::class,
            RunCheckStep::class,
            ProjectConfigurationParser::class,
            EffectiveProjectConfiguration::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }

    /**
     * Take the trusted reduction that allows a check outside the checker role.
     *
     * This is the documented operator escape hatch, not a test-only bypass: it
     * goes through the same configuration and the same SecurityPolicy that
     * production reads, and it changes the policy hash.
     */
    protected function reduceCheckerIsolation(): void
    {
        // The strict profile does not permit disabling the control at all, so
        // the escape hatch costs an explicitly non-strict profile plus an
        // acknowledged reduced mode — both visible and part of the policy hash.
        config([
            'ai6.security.profile' => SecurityProfile::CUSTOM->value,
            'ai6.security.measures.'.SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value => 'false',
            'ai6.security.acknowledge_reduced_mode' => 'true',
        ]);
        $this->app->forgetInstance(SecurityPolicy::class);
        $this->app->forgetInstance(CheckRunner::class);
    }

    /**
     * The programs a probe profile may name, including one that does not exist on disk.
     *
     * @return list<string>
     */
    protected function probeExecutables(): array
    {
        return [PHP_BINARY, $this->missingProgram()];
    }

    protected function missingProgram(): string
    {
        return str_replace('\\', '/', sys_get_temp_dir()).'/ai6-absent-check-tool';
    }

    /**
     * @param  list<string>  $arguments
     * @param  list<string>  $phases
     * @param  list<int>  $successExitCodes
     * @return array<string, mixed>
     */
    protected function probeProfile(
        array $arguments,
        array $phases = ['before_review'],
        bool $mutates = false,
        string $program = PHP_BINARY,
        array $successExitCodes = [0],
        string $workingDirectory = 'tree',
    ): array {
        return [
            'program' => $program,
            'arguments' => $arguments,
            'phases' => $phases,
            'working_directory' => $workingDirectory,
            'success_exit_codes' => $successExitCodes,
            'side_effects' => false,
            'network' => false,
            'mutates' => $mutates,
        ];
    }

    private function writeProbeScript(string $worktree, string $name, string $content): void
    {
        self::assertNotFalse(file_put_contents($worktree.'/'.$name, $content));
    }
}
