<?php

namespace App\AI6\Git;

use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class ReviewSubjectNormalizer
{
    public function __construct(
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
        private ReviewSubjectVerifier $verifier,
        private IsolatedTreeExport $exporter,
        private CheckTreeBinding $trees,
        private ProjectEffectLockName $lockNames,
        private RunOrchestrator $runs,
    ) {}

    public function normalize(Run $run, string $projectIdentifier, RedactionContext $context): Run
    {
        if ($run->worktree_path !== null) {
            // A bound checkpoint whose directory is gone — an interrupted
            // export, an operator cleanup, a reconciliation sweep — is not a
            // drift: its base, source, tree and diff stay bound and immutable.
            // It is rebuilt from that unchanged binding at its own deterministic
            // path, and the workspace check below proves the rebuilt tree hashes
            // to exactly the bound `review_workspace_hash`. Without this the run
            // could only park on a drift it does not have, and no resolver of
            // that wait would ever bring the checkpoint back.
            //
            // Only an executable run rebuilds. The checkpoint is disposable and
            // is removed once the report step is done (`AC-15`); recreating it
            // for a run that already left the executable range would put the
            // unattended code tree back on the machine.
            if (! $this->materialized($run)
                && in_array($run->state, [RunState::QUEUED, RunState::RUNNING, RunState::WAITING], true)
                && $run->worktree_path === $this->paths->runWorktreeDirectory($projectIdentifier, $run->id)) {
                $rebound = $this->verifier->verify($run, $projectIdentifier, $context);
                $this->materialize($run, $projectIdentifier, $rebound['subject'], $context);
            }
            $this->verifier->verify($run, $projectIdentifier, $context, true);

            return $run;
        }
        $verified = $this->verifier->verify($run, $projectIdentifier, $context);
        $subject = $verified['subject'];
        $final = $this->materialize($run, $projectIdentifier, $subject, $context);
        $workspaceHash = $this->trees->hash($final);

        try {
            return $this->runs->bindReviewCheckpoint(
                $run,
                $run->version,
                $final,
                $subject->kind,
                $subject->baseOid,
                $subject->sourceOid,
                $verified['tree_oid'],
                $verified['diff_hash'],
                $workspaceHash,
            );
        } catch (\Throwable $exception) {
            $this->paths->removeRunWorktreeDirectory($projectIdentifier, $run->id);
            throw $exception;
        }
    }

    /**
     * Export the bound source into the run's ref-free checkpoint directory.
     *
     * The detached staging worktree exists only inside this call and is removed
     * again before the export is bound, so the ref inventory of the managed
     * clone stays byte-identical (`GIT-011`, `GIT-010`).
     */
    private function materialize(Run $run, string $projectIdentifier, ReviewSubject $subject, RedactionContext $context): string
    {
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($projectIdentifier));
        $final = $this->paths->runWorktreeDirectory($projectIdentifier, $run->id);
        $stageName = $this->paths->reviewStageName($run->id);
        $stage = $this->paths->runWorktreeRoot($projectIdentifier).DIRECTORY_SEPARATOR.$stageName;
        $lockName = $this->lockNames->forProject($projectIdentifier);

        $this->paths->removeRunWorktreeDirectory($projectIdentifier, $stageName);
        $this->paths->removeRunWorktreeDirectory($projectIdentifier, $run->id);
        $refsBefore = $this->git->refs($repository, $context);
        $created = $this->git->createDetachedReviewWorktree($repository, $stage, $subject->sourceOid, $lockName, $context);
        if (! $created->succeeded()) {
            throw new ReviewSubjectException('review_staging_failed');
        }
        try {
            $this->exporter->export($stage, $final);
        } finally {
            $this->git->discardDetachedReviewWorktree($repository, $stage, $lockName, $context);
            $this->paths->removeRunWorktreeDirectory($projectIdentifier, $stageName);
        }
        if ($refsBefore !== $this->git->refs($repository, $context)) {
            $this->paths->removeRunWorktreeDirectory($projectIdentifier, $run->id);
            throw new ReviewSubjectException('managed_ref_inventory_changed');
        }

        return $final;
    }

    private function materialized(Run $run): bool
    {
        clearstatcache(true, (string) $run->worktree_path);

        return is_string($run->worktree_path) && is_dir($run->worktree_path) && ! is_link($run->worktree_path);
    }

    /** @return array{subject: ReviewSubject, tree_oid: string, diff_hash: string, workspace_hash: string|null} */
    public function verify(Run $run, string $projectIdentifier, RedactionContext $context): array
    {
        return $this->verifier->verify($run, $projectIdentifier, $context, true);
    }
}
