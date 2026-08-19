<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileRegistry;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * The shipped `git-diff-check` profile is executed once, unfaked.
 *
 * Its argument list is not obvious: the exported tree carries no Git metadata
 * (GIT-010), so the plain `git diff --check` cannot run there at all, and the
 * repo-less `--no-index` form has inverted exit codes — 1 is the clean case,
 * 3 reports whitespace errors. Both are proven here against the Git binary the
 * runtime actually uses, so the declared `success_exit_codes` rest on an
 * executed command rather than on an assumed one.
 */
final class ShippedGitDiffCheckProfileTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    /**
     * The Git the runtime actually uses.
     *
     * On the Linux runtime that is the configured `ai6.git.binary`; elsewhere
     * the same binary from PATH, so the exit-code contract is executed on every
     * platform instead of being skipped into an unproven assumption.
     */
    private function gitBinary(): string
    {
        $configured = config('ai6.git.binary');
        if (is_string($configured) && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        $found = (new ExecutableFinder)->find('git');
        if (! is_string($found)) {
            self::markTestSkipped('No Git binary is available in this runtime.');
        }

        return $found;
    }

    /** The exit codes the shipped profile declares are the ones Git really returns. */
    public function test_the_shipped_command_returns_one_when_clean_and_three_on_whitespace_errors(): void
    {
        $git = $this->gitBinary();
        $profile = CheckProfileRegistry::fromConfiguredValues()->get('git-diff-check');
        // Only the binary path may differ from the shipped profile; the
        // argument list under test is the shipped one, byte for byte.
        $command = [$git, ...$profile->arguments];
        self::assertSame(['--no-pager', 'diff', '--check', '--no-index', '--', 'baseline', 'tree'], $profile->arguments);

        $batch = $this->implementationTemp('git-diff-check-'.bin2hex(random_bytes(4)));
        foreach (['baseline', 'tree'] as $directory) {
            if (! is_dir($batch.'/'.$directory)) {
                self::assertTrue(mkdir($batch.'/'.$directory, 0700, true));
            }
        }

        // Clean: a file without whitespace errors. The tree differs from the
        // empty baseline, which is exactly the exit code 1 case.
        self::assertNotFalse(file_put_contents($batch.'/tree/clean.txt', "ok\n"));
        $clean = new Process($command, $batch);
        $clean->run();
        self::assertSame(1, $clean->getExitCode(), $clean->getErrorOutput());
        self::assertContains($clean->getExitCode(), $profile->successExitCodes);

        // Dirty: one trailing-whitespace line turns the same command into 3.
        self::assertNotFalse(file_put_contents($batch.'/tree/dirty.txt', "ok\ntrailing \n"));
        $dirty = new Process($command, $batch);
        $dirty->run();
        self::assertSame(3, $dirty->getExitCode(), $dirty->getErrorOutput());
        self::assertNotContains($dirty->getExitCode(), $profile->successExitCodes);
        self::assertStringContainsString('trailing whitespace', $dirty->getOutput().$dirty->getErrorOutput());
    }

    /** The same profile through the real runner separates the two states. */
    public function test_the_shipped_profile_runs_through_the_runner_and_separates_clean_from_dirty(): void
    {
        $git = $this->gitBinary();
        $shipped = config('ai6.checks.profiles.git-diff-check');
        self::assertIsArray($shipped);
        // Only the binary path is resolved for this runtime; arguments,
        // phases, working directory and exit codes stay the shipped ones.
        $shipped['program'] = $git;
        $this->bindCheckRuntime(['git-diff-check' => $shipped]);
        // The probe executables of the fixture do not contain Git.
        config(['ai6.process.policies.checker.allowed_executables' => [...$this->probeExecutables(), $git]]);
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(CheckProfileRegistry::class);
        $this->app->forgetInstance(CheckRunner::class);

        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-GITCHECK');
        $runner = $this->app->make(CheckRunner::class);

        $clean = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'git-diff-check');
        self::assertSame(CheckResultState::SUCCEEDED, $clean->state, $clean->redacted_output);
        self::assertSame(1, $clean->exit_code);

        // A trailing-whitespace line anywhere in the tree flips the result,
        // which is the whole-tree semantics this profile is documented to have.
        self::assertNotFalse(file_put_contents($worktree.'/ai6-check-whitespace.txt', "ok\ntrailing \n"));
        $dirty = $runner->run($run, CheckPhase::BEFORE_REVIEW, 'git-diff-check');
        self::assertSame(CheckResultState::FAILED, $dirty->state);
        self::assertSame(3, $dirty->exit_code);
        self::assertSame('check_failed', $dirty->reason);
    }

    /** The unpassable whole-tree gate is deliberately not a shipped final default. */
    public function test_the_shipped_final_defaults_do_not_mandate_the_whole_tree_check(): void
    {
        self::assertSame(['php-all'], config('ai6.project_config.server_defaults.checks.final'));
        self::assertTrue(CheckProfileRegistry::fromConfiguredValues()->has('git-diff-check'));
    }
}
