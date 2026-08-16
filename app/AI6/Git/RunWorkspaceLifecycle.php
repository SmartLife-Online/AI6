<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Shared\Redaction\RedactionContext;
use RuntimeException;

final readonly class RunWorkspaceLifecycle
{
    /** @var list<string> */
    private const ACTIVE_STATES = [
        RunState::QUEUED->value,
        RunState::RUNNING->value,
        RunState::WAITING->value,
    ];

    public function __construct(
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
        private RunOrchestrator $runs,
        private ProjectEffectLockName $lockNames,
    ) {}

    /**
     * Create exactly one worktree and one run branch for this run, anchored at the immutable
     * initial run base, and bind both to the run record.
     *
     * A crash between worktree creation and binding leaves an unbound remainder. The next call
     * removes that remainder before it creates the workspace again, so a run never ends up with a
     * second worktree and never stays permanently blocked.
     */
    public function create(Run $run, string $projectIdentifier, RedactionContext $context): Run
    {
        $runId = (string) $run->getKey();
        $branch = RunBranchName::forRun($projectIdentifier, $runId);
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($projectIdentifier));
        $worktree = $this->paths->runWorktreeDirectory($projectIdentifier, $runId);
        $lockName = $this->lockNames->forProject($projectIdentifier);

        if ($run->run_branch !== null || $run->worktree_path !== null) {
            if (! hash_equals((string) $run->run_branch, $branch->value)
                || ! hash_equals((string) $run->worktree_path, $worktree)) {
                throw new RuntimeException('The run workspace binding conflicts with its deterministic identity.');
            }

            return $run;
        }

        if ($this->hasRemnant($repository, $worktree, $branch, $context)) {
            $this->discard($repository, $projectIdentifier, $runId, $worktree, $branch, $lockName, $context);
        }

        $created = $this->git->createRunWorktree(
            $repository,
            $worktree,
            $branch->value,
            $run->initial_run_base_sha,
            $lockName,
            $context,
        );
        if (! $created->succeeded()) {
            $this->discard($repository, $projectIdentifier, $runId, $worktree, $branch, $lockName, $context);

            throw new RuntimeException('The run worktree could not be created.');
        }

        try {
            return $this->runs->bindWorkspace($run, $run->version, $branch->value, $worktree);
        } catch (RunTransitionConflict $conflict) {
            $this->discard($repository, $projectIdentifier, $runId, $worktree, $branch, $lockName, $context);

            throw $conflict;
        }
    }

    /**
     * Remove every workspace below the project worktree root that no active run is bound to.
     *
     * The reconciliation is idempotent and leaves the workspace of an active run untouched.
     *
     * @return list<string> the removed entry names
     */
    public function reconcile(string $projectIdentifier, RedactionContext $context): array
    {
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($projectIdentifier));
        $root = $this->paths->runWorktreeRoot($projectIdentifier);
        $lockName = $this->lockNames->forProject($projectIdentifier);
        $bound = $this->activeWorktreePaths();

        $removed = [];
        foreach ($this->paths->runWorktreeEntries($projectIdentifier) as $name) {
            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (ManagedProjectPath::validRunIdentifier($name) && in_array($path, $bound, true)) {
                continue;
            }

            if (ManagedProjectPath::validRunIdentifier($name)) {
                $this->git->discardRunWorkspace(
                    $repository,
                    $path,
                    RunBranchName::forRun($projectIdentifier, $name),
                    $lockName,
                    $context,
                );
            }
            $this->paths->removeRunWorktreeDirectory($projectIdentifier, $name);
            $removed[] = $name;
        }

        $this->git->pruneWorktrees($repository, $lockName, $context);

        return $removed;
    }

    /**
     * Detect an unbound remainder of an earlier attempt with read-only calls only.
     */
    private function hasRemnant(
        string $repository,
        string $worktree,
        RunBranchName $branch,
        RedactionContext $context,
    ): bool {
        clearstatcache(true, $worktree);

        return file_exists($worktree)
            || is_link($worktree)
            || $this->git->resolveRunBranch($repository, $branch, $context)->succeeded();
    }

    private function discard(
        string $repository,
        string $projectIdentifier,
        string $runId,
        string $worktree,
        RunBranchName $branch,
        string $lockName,
        RedactionContext $context,
    ): void {
        $this->git->discardRunWorkspace($repository, $worktree, $branch, $lockName, $context);
        $this->paths->removeRunWorktreeDirectory($projectIdentifier, $runId);
    }

    /** @return list<string> */
    private function activeWorktreePaths(): array
    {
        $paths = Run::query()
            ->whereIn('state', self::ACTIVE_STATES)
            ->whereNotNull('worktree_path')
            ->pluck('worktree_path')
            ->all();

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }
}
