<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;

/** Re-prove the clean, immutable checkpoint immediately before every review export. */
final readonly class ReviewCheckpointVerifier
{
    public function __construct(
        private HardenedGitRunner $git,
        private CanonicalDiffHasher $diffs,
    ) {}

    public function verify(Run $run, RedactionContext $context): void
    {
        if (! is_string($run->worktree_path) || ! is_string($run->run_branch)
            || ! is_string($run->checkpoint_commit_sha) || ! is_string($run->checkpoint_tree_sha)
            || ! is_string($run->checkpoint_diff_hash)) {
            throw new ReviewCheckpointException('checkpoint_binding_missing');
        }

        $status = $this->git->workingTreeStatus($run->worktree_path, $context);
        if (! $status->succeeded() || $status->output !== '') {
            throw new ReviewCheckpointException('checkpoint_worktree_dirty');
        }
        $head = $this->git->resolveRunBranch($run->worktree_path, new RunBranchName($run->run_branch), $context);
        if (! $head->succeeded() || ! hash_equals($run->checkpoint_commit_sha, trim($head->output))) {
            throw new ReviewCheckpointException('checkpoint_commit_mismatch');
        }
        $tree = $this->git->resolveTree($run->worktree_path, $run->checkpoint_commit_sha, $context);
        if (! $tree->succeeded() || ! hash_equals($run->checkpoint_tree_sha, trim($tree->output))) {
            throw new ReviewCheckpointException('checkpoint_tree_mismatch');
        }
        $raw = $this->git->canonicalRawDiff(
            $run->worktree_path,
            $run->initial_run_base_sha,
            $run->checkpoint_commit_sha,
            $context,
        );
        if (! $raw->succeeded()
            || ! hash_equals($run->checkpoint_diff_hash, $this->diffs->fromRaw($raw->output, $context)->hash)) {
            throw new ReviewCheckpointException('checkpoint_diff_mismatch');
        }
    }
}
