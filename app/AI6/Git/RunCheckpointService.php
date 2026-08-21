<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use RuntimeException;

final readonly class RunCheckpointService
{
    public function __construct(
        private HardenedGitRunner $git,
        private CanonicalDiffHasher $diffs,
        private RunOrchestrator $runs,
        private ProjectEffectLockName $lockNames,
    ) {}

    /**
     * Create the local run checkpoint and bind commit OID, tree OID and canonical diff hash.
     *
     * The call never pushes and never touches a foreign ref. It is idempotent while the bound
     * checkpoint still represents the worktree and evidence epoch; otherwise it advances the
     * checkpoint and leaves the predecessor in the audit table.
     */
    public function create(Run $run, string $projectIdentifier, RedactionContext $context): Run
    {
        if ($run->run_branch === null || $run->worktree_path === null) {
            throw new RuntimeException('A run checkpoint requires a bound workspace.');
        }
        $branchHead = $this->git->resolveRunBranch(
            $run->worktree_path,
            new RunBranchName($run->run_branch),
            $context,
        );
        if (! $branchHead->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($branchHead->output)) !== 1) {
            throw new RuntimeException('The run branch head could not be resolved.');
        }
        $branchHeadSha = trim($branchHead->output);
        $expectedHeadSha = $run->checkpoint_commit_sha ?? $run->initial_run_base_sha;
        if ($run->checkpoint_commit_sha !== null && hash_equals($expectedHeadSha, $branchHeadSha)
            && $this->runs->hasEffectiveCheckpoint($run)) {
            $status = $this->git->workingTreeStatus($run->worktree_path, $context);
            if (! $status->succeeded()) {
                throw new RuntimeException('The run worktree state could not be resolved.');
            }
            if ($status->output === '') {
                $this->assertChangedPathsBound($run, $run->checkpoint_commit_sha, $context);

                return $run;
            }
        }
        if (! hash_equals($expectedHeadSha, $branchHeadSha)) {
            $parent = $this->git->resolveFirstParent($run->worktree_path, $branchHeadSha, $context);
            if (! $parent->succeeded()
                || preg_match('/\A[0-9a-f]{64}\z/D', trim($parent->output)) !== 1
                || ! hash_equals($expectedHeadSha, trim($parent->output))) {
                throw new RuntimeException('The run branch advanced outside the checkpoint protocol.');
            }
            $status = $this->git->workingTreeStatus($run->worktree_path, $context);
            if ($status->succeeded() && $status->output === '') {
                return $this->bindResolvedCheckpoint($run, $branchHeadSha, $context);
            }

            if (! $status->succeeded()) {
                throw new RuntimeException('The run worktree state could not be resolved.');
            }

            $recovered = $this->bindResolvedCheckpoint($run, $branchHeadSha, $context);

            return $this->create($recovered, $projectIdentifier, $context);
        }
        $effectLockName = $this->lockNames->forProject($projectIdentifier);
        $staged = $this->git->stageRunCheckpoint($run->worktree_path, $effectLockName, $context);
        if (! $staged->succeeded()) {
            throw new RuntimeException('The complete run worktree could not be staged for checkpoint comparison.');
        }
        if ($run->checkpoint_commit_sha !== null) {
            if ($run->checkpoint_tree_sha === null || $run->checkpoint_diff_hash === null) {
                throw new RuntimeException('The persisted checkpoint binding is incomplete.');
            }
            $working = $this->git->canonicalWorkingTreeDiff($run->worktree_path, $run->initial_run_base_sha, $context);
            if (! $working->succeeded()) {
                throw new RuntimeException('The current run difference could not be resolved.');
            }
            $workingHash = $this->diffs->fromRaw($working->output, $context)->hash;
            if ($this->runs->hasEffectiveCheckpoint($run) && hash_equals($run->checkpoint_diff_hash, $workingHash)) {
                return $run;
            }
        }
        if ($run->created_at === null) {
            throw new RuntimeException('The run checkpoint requires a persisted creation time.');
        }

        $commit = $this->git->createRunCheckpoint(
            $run->worktree_path,
            $run->run_branch,
            'AI6 run checkpoint '.(string) $run->getKey(),
            $run->created_at->getTimestamp(),
            $effectLockName,
            $context,
        );
        if (! $commit->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($commit->output)) !== 1) {
            throw new RuntimeException('The local run checkpoint could not be created.');
        }
        $commitSha = trim($commit->output);

        return $this->bindResolvedCheckpoint($run, $commitSha, $context);
    }

    private function bindResolvedCheckpoint(Run $run, string $commitSha, RedactionContext $context): Run
    {
        $tree = $this->git->resolveTree($run->worktree_path, $commitSha, $context);
        if (! $tree->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($tree->output)) !== 1) {
            throw new RuntimeException('The checkpoint tree could not be resolved.');
        }

        $raw = $this->git->canonicalRawDiff($run->worktree_path, $run->initial_run_base_sha, $commitSha, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The checkpoint diff could not be resolved.');
        }

        $diff = $this->diffs->fromRaw($raw->output, $context);
        $this->assertChangedPathsPresent($run, $diff);

        return $this->runs->bindCheckpoint(
            $run,
            $run->version,
            $commitSha,
            trim($tree->output),
            $diff->hash,
        );
    }

    private function assertChangedPathsBound(Run $run, string $commitSha, RedactionContext $context): void
    {
        $raw = $this->git->canonicalRawDiff($run->worktree_path, $run->initial_run_base_sha, $commitSha, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The checkpoint diff could not be resolved.');
        }

        $this->assertChangedPathsPresent($run, $this->diffs->fromRaw($raw->output, $context));
    }

    private function assertChangedPathsPresent(Run $run, CanonicalDiff $diff): void
    {
        $checkpointPaths = array_column($diff->entries, 'path');
        foreach ($run->actual_changed_paths_snapshot ?? [] as $path) {
            if (! in_array($path, $checkpointPaths, true)) {
                throw new RunCheckpointConflict('checkpoint_path_missing');
            }
        }
    }
}
