<?php

namespace Tests\Feature\Runs;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Process\Process;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-03, TC-04 and TC-10: base binding, foreign state and the idempotent workspace reconciliation
 * against the real run record and the real managed path layout.
 */
final class RunWorkspaceLifecycleTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;
    use BuildsRunWorkspaceGitFixture;

    private ?string $managedRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    #[After]
    public function removeManagedRoot(): void
    {
        if ($this->managedRoot !== null && is_dir($this->managedRoot)) {
            $this->removeTree($this->managedRoot);
        }
        $this->managedRoot = null;
        $this->removeRunWorkspaceFixture();
    }

    public function test_an_advanced_run_base_never_reinterprets_the_immutable_worktree_anchor(): void
    {
        $fixture = $this->completedApproval('AI6-WS-1');
        $run = $this->finalizedRun($fixture);
        $anchor = $run->initial_run_base_sha;
        $branch = 'refs/heads/ai6/runs/'.$fixture['project']->project_identifier.'/'.$run->id;

        $bound = $this->app->make(RunOrchestrator::class)->bindWorkspace($run, $run->version, $branch, '/managed/worktrees/'.$run->id);
        self::assertSame($branch, $bound->run_branch);

        $advanced = str_repeat('b', 64);
        self::assertSame(1, Run::query()->whereKey($run->id)->update([
            'run_base_sha' => $advanced,
            'version' => $bound->version + 1,
        ]));

        $reloaded = Run::query()->findOrFail($run->id);
        self::assertSame($advanced, $reloaded->run_base_sha, 'The candidate base advances.');
        self::assertSame($anchor, $reloaded->initial_run_base_sha, 'The worktree anchor stays immutable.');
        self::assertSame($branch, $reloaded->run_branch);
        self::assertSame('/managed/worktrees/'.$run->id, $reloaded->worktree_path);
        self::assertNotSame($reloaded->run_base_sha, $reloaded->initial_run_base_sha);
    }

    public function test_a_workspace_binding_is_rejected_outside_the_run_branch_namespace(): void
    {
        $fixture = $this->completedApproval('AI6-WS-2');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);

        foreach ([
            'refs/heads/main',
            'refs/heads/ai6/runs/'.$fixture['project']->project_identifier.'/not-a-run',
            'refs/heads/ai6/runs/'.$run->id.'/'.$run->id,
        ] as $rejected) {
            try {
                $orchestrator->bindWorkspace($run, $run->version, $rejected, '/managed/worktrees/'.$run->id);
                self::fail('The orchestrator accepted the foreign branch '.$rejected);
            } catch (RunTransitionConflict $conflict) {
                self::assertSame('invalid_workspace_binding', $conflict->reason);
            }
        }

        self::assertNull(Run::query()->findOrFail($run->id)->run_branch);
    }

    public function test_a_bound_checkpoint_stays_immutable_and_cannot_be_cleared(): void
    {
        $fixture = $this->completedApproval('AI6-WS-3');
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $commit = str_repeat('1', 64);
        $tree = str_repeat('2', 64);
        $diff = str_repeat('3', 64);

        $bound = $orchestrator->bindCheckpoint($run, $run->version, $commit, $tree, $diff);
        self::assertSame($commit, $bound->checkpoint_commit_sha);
        self::assertSame($tree, $bound->checkpoint_tree_sha);
        self::assertSame($diff, $bound->checkpoint_diff_hash);

        try {
            $orchestrator->bindCheckpoint($bound, $bound->version, str_repeat('4', 64), $tree, $diff);
            self::fail('A second checkpoint binding was accepted.');
        } catch (RunTransitionConflict $conflict) {
            self::assertSame('stale_run_version', $conflict->reason);
        }

        $this->expectException(QueryException::class);
        Run::query()->whereKey($run->id)->update([
            'checkpoint_commit_sha' => null,
            'checkpoint_tree_sha' => null,
            'checkpoint_diff_hash' => null,
            'version' => $bound->version + 1,
        ]);
    }

    public function test_reconciliation_removes_only_workspaces_without_an_active_run_and_repeats_identically(): void
    {
        $fixture = $this->completedApproval('AI6-WS-4');
        $run = $this->finalizedRun($fixture);
        $identifier = (string) $fixture['project']->project_identifier;
        $paths = $this->managedPaths($identifier);
        $lifecycle = $this->app->make(RunWorkspaceLifecycle::class);
        $context = new RedactionContext($identifier, $run->id, 'run-workspace-reconcile');

        $active = $paths->runWorktreeDirectory($identifier, $run->id);
        self::assertTrue(mkdir($active, 0700));
        $this->app->make(RunOrchestrator::class)->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$identifier.'/'.$run->id,
            $active,
        );
        $orphanRun = '123e4567-e89b-42d3-a456-426614174000';
        $orphan = $paths->runWorktreeDirectory($identifier, $orphanRun);
        self::assertTrue(mkdir($orphan.'/nested', 0700, true));
        self::assertNotFalse(file_put_contents($orphan.'/nested/file.txt', 'orphan'));
        $foreign = $paths->runWorktreeRoot($identifier).'/not-a-run-identifier';
        self::assertTrue(mkdir($foreign, 0700));

        $removed = $lifecycle->reconcile($identifier, $context);

        self::assertSame([$orphanRun, 'not-a-run-identifier'], $removed);
        self::assertDirectoryExists($active, 'The workspace of an active run stays.');
        self::assertDirectoryDoesNotExist($orphan);
        self::assertDirectoryDoesNotExist($foreign);
        self::assertSame([], $lifecycle->reconcile($identifier, $context), 'The reconciliation is idempotent.');
        self::assertDirectoryExists($active);

        self::assertSame(1, Run::query()->whereKey($run->id)->update([
            'state' => RunState::COMPLETED->value,
            'phase' => RunPhase::PUBLISH->value,
            'version' => Run::query()->findOrFail($run->id)->version + 1,
        ]));
        self::assertSame([$run->id], $lifecycle->reconcile($identifier, $context));
        self::assertDirectoryDoesNotExist($active, 'A terminal run releases its workspace.');
    }

    private function managedPaths(string $projectIdentifier): ManagedProjectPath
    {
        $root = sys_get_temp_dir().'/ai6-managed-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700, true));
        $this->managedRoot = $root;
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $replacement = new ControlOperationConfiguration(
            $root,
            $root.'/deploy-keys',
            $configuration->sshKeygenBinary,
            $configuration->sshKeygenWrapper,
            $configuration->leaseSeconds,
            $configuration->heartbeatSeconds,
            $configuration->reconcilerSeconds,
            $configuration->maxAttempts,
            $configuration->knownHostsFile,
            $configuration->managedRefAllowlist,
            $configuration->staleSeconds,
            $configuration->reconciliationBudget,
        );
        $this->app->instance(ControlOperationConfiguration::class, $replacement);
        $paths = new ManagedProjectPath($replacement);
        $this->app->instance(ManagedProjectPath::class, $paths);
        $this->app->instance(HardenedGitRunner::class, $this->runWorkspaceRunner($this->runWorkspaceRoot()));
        $this->app->forgetInstance(RunWorkspaceLifecycle::class);

        $repository = $paths->repositoryDirectory($projectIdentifier);
        self::assertTrue(mkdir($repository, 0700, true));
        (new Process(['git', 'init', '--object-format=sha256', '--initial-branch=main'], $repository, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]))->mustRun();

        return $paths;
    }

    private function removeTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if (in_array($entry, ['.', '..'], true)) {
                continue;
            }
            $child = $path.'/'.$entry;
            @chmod($child, is_dir($child) && ! is_link($child) ? 0700 : 0600);
            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}
