<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\DelegatingProcessIsolationBoundary;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Git\IsolatedTreeExporter;
use App\AI6\Git\RunPatchImporter;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\RunImplementation;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use RuntimeException;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ImplementationImportIsolationTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    /** TC-02 */
    public function test_a_tampered_reported_path_and_post_validation_patch_leave_no_partial_state(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC02');
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $isolated = $this->implementationTemp('tamper-view-'.bin2hex(random_bytes(3)));
        $view = $isolated.'/tree-a';
        (new IsolatedTreeExporter)->export($prepared['worktree'], $view, true);
        file_put_contents($view.'/app/Other.php', "<?php\n");
        $planned = $this->app->make(RunPatchImporter::class)->plan($prepared['run'], $view, ['app']);
        self::assertContains('app/Other.php', array_map(static fn ($change): string => $change->path, $planned));
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));

        $this->removeTree($view);
        $view = $isolated.'/tree-b';
        (new IsolatedTreeExporter)->export($prepared['worktree'], $view, true);
        file_put_contents($view.'/app/Example.php', "<?php\n\n// tampered after validation\n");
        if (DIRECTORY_SEPARATOR === '/') {
            symlink($view.'/app/Example.php', $view.'/app/link.php');
            try {
                $this->app->make(RunPatchImporter::class)->import($prepared['run'], $view, ['app']);
                self::fail('A symlink must block the import.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('symbolic link', $exception->getMessage());
            }
        }
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertFileDoesNotExist($prepared['worktree'].'/app/link.php');

        $this->removeTree($view);
        $view = $isolated.'/tree-c';
        (new IsolatedTreeExporter)->export($prepared['worktree'], $view, true);
        file_put_contents($view.'/app/binary.bin', "\x00\x01\x02");
        try {
            $this->app->make(RunPatchImporter::class)->plan($prepared['run'], $view, ['app']);
            self::fail('An unknown binary must block the import.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('binary', $exception->getMessage());
        }
        self::assertFileDoesNotExist($prepared['worktree'].'/app/binary.bin');
        $this->removeTree($view);
    }

    /** TC-03 */
    public function test_git_metadata_from_the_isolated_view_never_reaches_the_managed_clone(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC03');
        self::assertDirectoryExists($prepared['worktree'].'/.git');
        $head = (string) file_get_contents($prepared['worktree'].'/.git/HEAD');
        $isolated = $this->implementationTemp('meta-view');
        $view = $isolated.'/tree';
        (new IsolatedTreeExporter)->export($prepared['worktree'], $view, true);
        self::assertDirectoryDoesNotExist($view.'/.git');
        mkdir($view.'/.git/hooks', 0700, true);
        file_put_contents($view.'/.git/HEAD', 'ref: refs/heads/evil');
        file_put_contents($view.'/.git/hooks/pre-commit', "#!/bin/sh\n");
        try {
            $this->app->make(RunPatchImporter::class)->plan($prepared['run'], $view, ['app']);
            self::fail('Git metadata in the isolated view must block the worker import.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Git metadata', $exception->getMessage());
        }
        self::assertDirectoryExists($prepared['worktree'].'/.git');
        self::assertSame($head, (string) file_get_contents($prepared['worktree'].'/.git/HEAD'));
        $this->removeTree($view);
    }

    /** TC-04 */
    public function test_the_agent_process_cannot_reach_credentials_or_the_managed_worktree(): void
    {
        $forbidden = ['APP_KEY', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY'];
        $foreignBatch = null;
        $foreignSecret = null;
        foreach ($forbidden as $name) {
            putenv($name.'=must-not-pass');
        }
        try {
            $prepared = $this->preparedImplementationRun('AI6-019-TC04');
            $foreignBatch = $prepared['isolatedRoot'].'/foreign-batch';
            self::assertTrue(mkdir($foreignBatch, 0700));
            $foreignSecret = $foreignBatch.'/foreign-state';
            self::assertNotFalse(file_put_contents($foreignSecret, 'foreign-state-bytes'));
            $adapter = new FakeAgentAdapter(
                AgentScenario::SUCCESS,
                additionalPathProbes: [$foreignBatch, $foreignSecret],
            );
            $this->app->instance(FakeAgentAdapter::class, $adapter);
            $this->app->instance(AgentAdapter::class, $adapter);
            $this->app->forgetInstance(RunImplementation::class);
            $job = $this->executeImplement($prepared['run']);
            self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
            self::assertGreaterThan(0, $adapter->turnCount);
            foreach ($forbidden as $name) {
                self::assertSame('missing', $adapter->lastAccessProbes['env:'.$name] ?? null, $name);
            }
            self::assertSame('denied', $adapter->lastAccessProbes['path:'.$prepared['worktree']] ?? null);
            self::assertSame('denied', $adapter->lastAccessProbes['path:'.$prepared['worktree'].'/.git'] ?? null);
            self::assertSame('denied', $adapter->lastAccessProbes['path:'.$foreignBatch] ?? null);
            self::assertSame('denied', $adapter->lastAccessProbes['path:'.$foreignSecret] ?? null);
        } finally {
            if (is_string($foreignSecret) && is_file($foreignSecret)) {
                self::assertTrue(unlink($foreignSecret));
            }
            if (is_string($foreignBatch) && is_dir($foreignBatch)) {
                self::assertTrue(rmdir($foreignBatch));
            }
            foreach ($forbidden as $name) {
                putenv($name);
            }
        }
    }

    /** TC-04 */
    public function test_the_implementation_turn_starts_with_the_shipped_agent_policy(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The shipped agent policy requires a process-group boundary.');
        }

        $policy = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::AGENT);
        self::assertSame([PHP_BINARY], $policy->allowedExecutables);
        self::assertTrue($policy->requiresProcessGroup);
        self::assertInstanceOf(DelegatingProcessIsolationBoundary::class, $this->app->make(ProcessIsolationBoundary::class));

        $prepared = $this->preparedImplementationRun('AI6-019-TC04-SHIPPED', shippedProcessPolicy: true);
        $adapter = $this->app->make(FakeAgentAdapter::class);
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame('denied', $adapter->lastAccessProbes['path:'.$prepared['worktree']] ?? null);
        self::assertSame('denied', $adapter->lastAccessProbes['path:'.$prepared['worktree'].'/.git'] ?? null);
        self::assertSame([PHP_BINARY], ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::AGENT)->allowedExecutables);
        self::assertInstanceOf(DelegatingProcessIsolationBoundary::class, $this->app->make(ProcessIsolationBoundary::class));
    }

    public function test_has_registered_handler_is_true_for_implement(): void
    {
        $prepared = $this->preparedImplementationRun('AI6-019-TC03B', scenario: AgentScenario::SUCCESS);
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state);
        unset($prepared);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
