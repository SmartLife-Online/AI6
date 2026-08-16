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
     * The call never pushes and never touches a foreign ref. It is idempotent: an already bound
     * checkpoint is returned unchanged instead of producing a second commit.
     */
    public function create(Run $run, string $projectIdentifier, RedactionContext $context): Run
    {
        if ($run->checkpoint_commit_sha !== null) {
            if ($run->checkpoint_tree_sha === null || $run->checkpoint_diff_hash === null) {
                throw new RuntimeException('The persisted checkpoint binding is incomplete.');
            }

            return $run;
        }
        if ($run->run_branch === null || $run->worktree_path === null) {
            throw new RuntimeException('A run checkpoint requires a bound workspace.');
        }
        if ($run->created_at === null) {
            throw new RuntimeException('The run checkpoint requires a persisted creation time.');
        }

        $commit = $this->git->createRunCheckpoint(
            $run->worktree_path,
            $run->run_branch,
            'AI6 run checkpoint '.(string) $run->getKey(),
            $run->created_at->getTimestamp(),
            $this->lockNames->forProject($projectIdentifier),
            $context,
        );
        if (! $commit->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($commit->output)) !== 1) {
            throw new RuntimeException('The local run checkpoint could not be created.');
        }
        $commitSha = trim($commit->output);

        $tree = $this->git->resolveTree($run->worktree_path, $commitSha, $context);
        if (! $tree->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($tree->output)) !== 1) {
            throw new RuntimeException('The checkpoint tree could not be resolved.');
        }

        $raw = $this->git->canonicalRawDiff($run->worktree_path, $run->initial_run_base_sha, $commitSha, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The checkpoint diff could not be resolved.');
        }

        return $this->runs->bindCheckpoint(
            $run,
            $run->version,
            $commitSha,
            trim($tree->output),
            $this->diffs->fromRaw($raw->output, $context)->hash,
        );
    }
}
