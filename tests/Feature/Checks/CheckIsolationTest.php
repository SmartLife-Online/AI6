<?php

namespace Tests\Feature\Checks;

use App\AI6\Agents\DelegatingProcessIsolationBoundary;
use App\AI6\Checks\CheckContainmentBoundary;
use App\AI6\Checks\CheckExecutionRoleRequired;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-04 secret and network negative test, TC-05 Git metadata, hook and ref isolation.
 *
 * The environment, Git metadata and result visibility halves run everywhere and
 * really start a process. The container half of the network promise —
 * `network_mode: none` and the absent interfaces the ProcessIsolationVerifier
 * asserts in the checker role — belongs to the Linux runtime and is bound by
 * CheckerExecutionBoundaryTest and CheckerProcessBoundaryTest.
 */
final class CheckIsolationTest extends TicketUiTestCase
{
    use BuildsCheckFixture;

    /** TC-04: no credential and no variable outside the allowlist reaches the process. */
    public function test_the_check_process_sees_no_credentials_and_no_variable_outside_the_allowlist(): void
    {
        putenv('APP_KEY=base64:forbidden-app-key-value');
        putenv('DB_DATABASE=/forbidden/primary.sqlite');
        putenv('MAIL_PASSWORD=forbidden-smtp-password');
        putenv('AI6_GIT_SSH_KEY=/forbidden/deploy-key');

        try {
            $this->bindCheckRuntime(['probe-env' => $this->probeProfile(['ai6-check-env.php'])]);
            ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-ENV');
            self::assertNotFalse(file_put_contents(
                $worktree.'/ai6-check-env.php',
                "<?php\n\nfwrite(STDOUT, implode(\"\\n\", array_keys(getenv())) . \"\\n\");\nexit(0);\n",
            ));

            $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-env');
            $names = array_values(array_filter(array_map('trim', explode("\n", $record->redacted_output))));

            self::assertSame(CheckResultState::SUCCEEDED, $record->state);
            self::assertNotSame([], $names, 'The probe must actually report its environment.');
            self::assertContains('AI6_CHECK_PROFILE', $names, 'The one allowlisted check variable is present.');

            $allowed = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER)->environmentAllowlist;
            foreach ($names as $name) {
                if (DIRECTORY_SEPARATOR === '/' || in_array($name, $allowed, true)) {
                    self::assertContains($name, $allowed, 'A variable outside the checker allowlist reached the process.');

                    continue;
                }
                // Windows injects a few of its own variables into every child
                // regardless of the cleared environment; the runtime that
                // carries this promise is POSIX. What must hold on both is that
                // no application variable survives the allowlist.
                self::assertDoesNotMatchRegularExpression(
                    '/\A(AI6_|APP_|DB_|MAIL_|GIT_|REDIS_|AWS_)/',
                    $name,
                    'An application variable outside the checker allowlist reached the process.',
                );
            }
            foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY'] as $forbidden) {
                self::assertNotContains($forbidden, $names);
            }
            self::assertStringNotContainsString('forbidden', $record->redacted_output);
        } finally {
            foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY'] as $name) {
                putenv($name);
            }
        }
    }

    /** TC-04: a network attempt is a visible failure, never a silent success. */
    public function test_a_failing_network_attempt_is_visible_in_the_result(): void
    {
        $this->bindCheckRuntime(['probe-net' => $this->probeProfile(['ai6-check-net.php'])]);
        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-NETFAIL');
        // 192.0.2.1 is TEST-NET-1 (RFC 5737) and never routable, so the attempt
        // deterministically fails here as it does inside network_mode: none.
        self::assertNotFalse(file_put_contents(
            $worktree.'/ai6-check-net.php',
            "<?php\n\n\$socket = @fsockopen('192.0.2.1', 80, \$code, \$message, 2);\n"
            ."if (\$socket === false) {\n    fwrite(STDERR, \"network attempt failed\\n\");\n    exit(1);\n}\n"
            ."fclose(\$socket);\nexit(0);\n",
        ));

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-net');

        self::assertSame(CheckResultState::FAILED, $record->state, 'A blocked network attempt must not end as a green check.');
        self::assertSame('check_failed', $record->reason);
        self::assertStringContainsString('network attempt failed', $record->redacted_output);
    }

    /** TC-05: no Git metadata of the managed clone is reachable from the check process. */
    public function test_the_check_process_reaches_no_git_metadata_of_the_managed_clone(): void
    {
        $this->bindCheckRuntime(['probe-git' => $this->probeProfile(['ai6-check-git.php'])]);
        ['run' => $run, 'worktree' => $worktree] = $this->checkableRun('AI6-021-GIT');
        // The fixture worktree really is a Git repository, so the export has
        // something to strip.
        self::assertDirectoryExists($worktree.'/.git');
        self::assertNotFalse(file_put_contents(
            $worktree.'/ai6-check-git.php',
            "<?php\n\n\$found = [];\n"
            ."foreach (['.git', '.git/refs', '.git/hooks', '.git/index', '.git/objects', '.git/commondir', '.git/objects/info/alternates', '../.git', '../../.git'] as \$path) {\n"
            ."    if (file_exists(__DIR__ . '/' . \$path)) { \$found[] = \$path; }\n}\n"
            ."fwrite(STDOUT, 'reachable=' . implode(',', \$found) . \"\\n\");\nexit(0);\n",
        ));

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-git');

        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
        self::assertStringContainsString('reachable=', $record->redacted_output);
        self::assertStringContainsString("reachable=\n", $record->redacted_output."\n", 'The check reached Git metadata: '.$record->redacted_output);
    }

    /**
     * The shipped wiring runs a check for real once the isolation control is
     * reduced — through the unmodified dispatcher, not a substituted seam.
     */
    public function test_the_shipped_wiring_runs_a_check_green_under_a_reduced_isolation_control(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-021-ROLE');

        self::assertInstanceOf(
            DelegatingProcessIsolationBoundary::class,
            $this->app->make(ProcessIsolationBoundary::class),
            'The check must run through the shipped isolation dispatcher, not a test substitute.',
        );
        self::assertSame('worker', config('ai6.runtime_role'));
        self::assertFalse($this->app->make(SecurityPolicy::class)
            ->isEnabled(SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION));

        $record = $this->app->make(CheckRunner::class)->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');

        self::assertSame(CheckResultState::SUCCEEDED, $record->state);
        self::assertSame(0, $record->exit_code);
        self::assertStringContainsString('check ok', $record->redacted_output);
    }

    /**
     * The default refuses: outside the checker role nothing runs.
     *
     * The worker carries the managed clone, its deploy keys and normal network,
     * while a check profile executes the managed project's own untrusted code.
     * With the shipped control active the execution is refused before a process
     * starts and before any result exists (AGT-007, GIT-010, SEC-005).
     */
    public function test_a_check_outside_the_checker_role_is_refused_while_the_control_is_active(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        ['run' => $run] = $this->checkableRun('AI6-021-FENCE');

        // Restore the shipped default the fixture reduced.
        config([
            'ai6.security.measures.'.SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value => 'true',
            'ai6.security.profile' => SecurityProfile::STRICT->value,
            'ai6.security.acknowledge_reduced_mode' => 'false',
        ]);
        $this->app->forgetInstance(SecurityPolicy::class);
        $this->app->forgetInstance(CheckRunner::class);
        $runner = $this->app->make(CheckRunner::class);

        self::assertNotSame('checker', config('ai6.runtime_role'));
        self::assertFalse($runner->mayExecuteHere());

        try {
            $runner->run($run, CheckPhase::BEFORE_REVIEW, 'probe-ok');
            self::fail('A check outside the checker role must be refused.');
        } catch (CheckExecutionRoleRequired $exception) {
            self::assertSame('worker', $exception->runtimeRole);
        }

        self::assertSame(0, CheckResultRecord::query()->where('run_id', $run->id)->count());
    }

    /** Inside the checker role the control permits execution without any reduction. */
    public function test_the_checker_role_may_execute_while_the_control_stays_active(): void
    {
        $this->bindCheckRuntime(['probe-ok' => $this->probeProfile(['ai6-check-ok.php'])]);
        config([
            'ai6.security.measures.'.SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value => 'true',
            'ai6.security.profile' => SecurityProfile::STRICT->value,
            'ai6.security.acknowledge_reduced_mode' => 'false',
            'ai6.runtime_role' => 'checker',
        ]);
        $this->app->forgetInstance(SecurityPolicy::class);
        $this->app->forgetInstance(CheckRunner::class);

        self::assertTrue($this->app->make(CheckRunner::class)->mayExecuteHere());
    }

    /** TC-05: the containment boundary refuses a tree that still carries Git metadata. */
    public function test_the_containment_boundary_refuses_a_tree_with_git_metadata(): void
    {
        $root = $this->implementationTemp('containment-'.bin2hex(random_bytes(4)));
        $tree = $root.'/tree';
        $outputs = $root.'/outputs';
        foreach ([$tree, $outputs, $outputs.'/result', $outputs.'/artifacts'] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }
        $policy = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER);
        $context = new RedactionContext('1', null, 'check-containment');
        $boundary = new CheckContainmentBoundary;

        $clean = new ProcessRequest(
            [PHP_BINARY, '--version'], $tree,
            ['PATH'], [], $context,
            policy: ProcessPolicyName::CHECKER,
            resultDirectory: $outputs.'/result',
            artifactDirectory: $outputs.'/artifacts',
        );
        // The clean case must pass, otherwise the negative case below proves nothing.
        $boundary->assertIsolated($clean, $policy);

        self::assertTrue(mkdir($tree.'/.git', 0700));
        $this->expectException(ProcessStartRejectedException::class);
        $this->expectExceptionMessage('exposes Git metadata');
        $boundary->assertIsolated($clean, $policy);
    }

    /** The result and artifact directories may never lie inside the tree a check can write to. */
    public function test_the_containment_boundary_refuses_result_directories_inside_the_tree(): void
    {
        $root = $this->implementationTemp('containment-inside-'.bin2hex(random_bytes(4)));
        $tree = $root.'/tree';
        foreach ([$tree, $tree.'/result', $tree.'/artifacts'] as $directory) {
            self::assertTrue(mkdir($directory, 0700, true));
        }

        $this->expectException(ProcessStartRejectedException::class);
        (new CheckContainmentBoundary)->assertIsolated(
            new ProcessRequest(
                [PHP_BINARY, '--version'], $tree,
                ['PATH'], [], new RedactionContext('1', null, 'check-containment'),
                policy: ProcessPolicyName::CHECKER,
                resultDirectory: $tree.'/result',
                artifactDirectory: $tree.'/artifacts',
            ),
            ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER),
        );
    }
}
