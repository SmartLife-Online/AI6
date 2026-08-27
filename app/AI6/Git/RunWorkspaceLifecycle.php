<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\RunType;
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
        if ($run->run_type === RunType::REVIEW_ONLY) {
            throw new RuntimeException('A review-only run cannot create a branch workspace.');
        }
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

        $inFlight = $this->activeReviewOnlyRunIdentifiers();

        $removed = [];
        foreach ($this->paths->runWorktreeEntries($projectIdentifier) as $name) {
            $path = $root.DIRECTORY_SEPARATOR.$name;
            if (ManagedProjectPath::validRunIdentifier($name) && in_array($path, $bound, true)) {
                continue;
            }
            // Source normalization creates the detached staging directory and
            // the export before it binds `worktree_path`, so neither is visible
            // to the bound-path check above. Sweeping them mid-flight would
            // destroy the checkpoint of a run that is still executable and
            // unregister its live staging worktree through the prune below.
            if (in_array($this->reviewOnlyOwner($name), $inFlight, true)) {
                continue;
            }

            $reviewOnly = Run::query()->whereKey($name)->where('run_type', RunType::REVIEW_ONLY->value)->exists();
            if (ManagedProjectPath::validRunIdentifier($name) && ! $reviewOnly) {
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

    public function cleanupReviewOnly(Run $run, string $projectIdentifier, RedactionContext $context): void
    {
        if ($run->run_type !== RunType::REVIEW_ONLY || ! ManagedProjectPath::validRunIdentifier($run->id)) {
            throw new RuntimeException('The review-only cleanup binding is invalid.');
        }
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($projectIdentifier));
        $this->paths->removeRunWorktreeDirectory($projectIdentifier, $run->id);
        // A hard kill can skip the normalizer's own staging cleanup. While the
        // run is executable the reconciliation deliberately leaves that entry
        // alone, so the disposable staging is removed here at the latest and
        // never outlives its run.
        $this->paths->removeRunWorktreeDirectory($projectIdentifier, $this->paths->reviewStageName($run->id));
        $this->git->pruneWorktrees($repository, $this->lockNames->forProject($projectIdentifier), $context);
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

    /**
     * The run a worktree-root entry belongs to, for both review-only forms.
     *
     * Returns the run identifier of `<runId>` and of the detached staging
     * directory `.review-stage-<runId>`, and null for every other name.
     */
    private function reviewOnlyOwner(string $name): ?string
    {
        $runId = str_starts_with($name, ManagedProjectPath::REVIEW_STAGE_PREFIX)
            ? substr($name, strlen(ManagedProjectPath::REVIEW_STAGE_PREFIX))
            : $name;

        return ManagedProjectPath::validRunIdentifier($runId) ? $runId : null;
    }

    /** @return list<string> */
    private function activeReviewOnlyRunIdentifiers(): array
    {
        return Run::query()
            ->whereIn('state', self::ACTIVE_STATES)
            ->where('run_type', RunType::REVIEW_ONLY->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
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
