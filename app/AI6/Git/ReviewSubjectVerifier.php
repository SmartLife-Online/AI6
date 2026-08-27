<?php

namespace App\AI6\Git;

use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class ReviewSubjectVerifier
{
    public function __construct(
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
        private ReviewSubjectReference $references,
        private CanonicalDiffHasher $diffs,
        private CheckTreeBinding $trees,
    ) {}

    /** @return array{subject: ReviewSubject, tree_oid: string, diff_hash: string, workspace_hash: string|null} */
    public function verify(Run $run, string $projectIdentifier, RedactionContext $context, bool $workspace = false): array
    {
        if ($run->run_type !== RunType::REVIEW_ONLY) {
            throw new ReviewSubjectException('run_type_not_review_only');
        }
        $reference = $run->review_subject_reference;
        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        if (! $approval instanceof TicketApproval) {
            throw new ReviewSubjectException('approval_source_binding_missing');
        }
        $reviewedControlSha = $approval->getAttributes()['reviewed_control_sha'] ?? null;
        if (! is_string($reviewedControlSha)) {
            throw new ReviewSubjectException('approval_source_binding_missing');
        }
        $subject = $this->references->decode($reference, $context);
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($projectIdentifier));
        $verified = $this->verifyResolved($repository, $subject, $reviewedControlSha, (int) $run->project_id, $context);

        $workspaceHash = null;
        if ($workspace) {
            if (! is_string($run->worktree_path) || ! is_dir($run->worktree_path) || is_link($run->worktree_path)) {
                throw new ReviewSubjectException('review_workspace_missing');
            }
            $workspaceHash = $this->trees->hash($run->worktree_path);
            if (! is_string($run->review_workspace_hash) || ! hash_equals($run->review_workspace_hash, $workspaceHash)) {
                throw new ReviewSubjectException('review_workspace_drift');
            }
        }

        return ['subject' => $subject, 'tree_oid' => $verified['tree_oid'], 'diff_hash' => $verified['diff_hash'], 'workspace_hash' => $workspaceHash];
    }

    public function verifyApproval(Project $project, string $expectedBase, string $reference, RedactionContext $context): void
    {
        if (! is_string($project->project_identifier) || $project->project_identifier === '') {
            throw new ReviewSubjectException('managed_project_missing');
        }
        $repository = $this->paths->assertRepository($this->paths->repositoryDirectory($project->project_identifier));
        $this->verifyResolved($repository, $this->references->decode($reference, $context), $expectedBase, (int) $project->id, $context);
    }

    /** @return array{tree_oid: string, diff_hash: string} */
    private function verifyResolved(string $repository, ReviewSubject $subject, string $expectedBase, int $projectId, RedactionContext $context): array
    {
        if (! hash_equals($expectedBase, $subject->baseOid)) {
            throw new ReviewSubjectException('source_base_mismatch');
        }

        if ($subject->kind === ReviewSubjectKind::MANAGED_BRANCH) {
            $resolved = $this->git->resolveRef($repository, (string) $subject->ref, $context);
            if (! $resolved->succeeded() || ! hash_equals($subject->sourceOid, trim($resolved->output))) {
                throw new ReviewSubjectException('managed_branch_drift');
            }
        }
        if (! $this->git->isAncestor($repository, $subject->baseOid, $subject->sourceOid, $context)) {
            throw new ReviewSubjectException('source_history_unrelated');
        }
        if ($subject->kind === ReviewSubjectKind::SINGLE_COMMIT) {
            try {
                $commit = $this->git->inspectSingleParentCommit($repository, $subject->sourceOid, $context);
            } catch (TicketMutationGitConflict) {
                // The commit was read, and its shape is the refusal: more than
                // one parent, or no single bound tree.
                throw new ReviewSubjectException('single_commit_merge_forbidden');
            } catch (\Throwable) {
                // The commit could not be inspected at all. Reporting that as a
                // merge commit would name a property nobody observed (AC-03).
                throw new ReviewSubjectException('single_commit_unreadable');
            }
            if (! hash_equals($subject->baseOid, $commit['parent_oid'])) {
                throw new ReviewSubjectException('single_commit_parent_mismatch');
            }
        }

        $tree = $this->git->resolveTree($repository, $subject->sourceOid, $context);
        if (! $tree->succeeded() || preg_match('/\A[0-9a-f]{64}\z/D', trim($tree->output)) !== 1) {
            throw new ReviewSubjectException('source_tree_unavailable');
        }
        $treeOid = trim($tree->output);
        $raw = $this->git->canonicalRawDiff($repository, $subject->baseOid, $subject->sourceOid, $context);
        if (! $raw->succeeded()) {
            throw new ReviewSubjectException('source_diff_unavailable');
        }
        $diffHash = $this->diffs->fromRaw($raw->output, $context)->hash;

        if (in_array($subject->kind, [ReviewSubjectKind::VALIDATED_PATCH, ReviewSubjectKind::CHECKPOINT], true)) {
            $sourceRun = Run::query()->find($subject->sourceRunId);
            if (! $sourceRun instanceof Run || (int) $sourceRun->project_id !== $projectId
                || ! hash_equals((string) $sourceRun->run_base_sha, $subject->baseOid)
                || ! hash_equals((string) $sourceRun->checkpoint_commit_sha, $subject->sourceOid)
                || ! hash_equals((string) $sourceRun->checkpoint_tree_sha, (string) $subject->expectedTreeOid)
                || ! hash_equals((string) $sourceRun->checkpoint_diff_hash, (string) $subject->expectedDiffHash)) {
                throw new ReviewSubjectException($subject->kind === ReviewSubjectKind::VALIDATED_PATCH
                    ? 'validated_patch_binding_mismatch' : 'checkpoint_binding_mismatch');
            }
            if ($subject->kind === ReviewSubjectKind::VALIDATED_PATCH
                && ($sourceRun->run_type !== RunType::IMPLEMENTATION
                    || ! RunArtifact::query()->where('run_id', $sourceRun->id)
                        ->where('kind', RunArtifactKind::IMPLEMENTATION_SUMMARY->value)->exists())) {
                throw new ReviewSubjectException('validated_patch_provenance_missing');
            }
        }
        if ($subject->expectedTreeOid !== null && ! hash_equals($subject->expectedTreeOid, $treeOid)) {
            throw new ReviewSubjectException('source_tree_mismatch');
        }
        if ($subject->expectedDiffHash !== null && ! hash_equals($subject->expectedDiffHash, $diffHash)) {
            throw new ReviewSubjectException('source_diff_mismatch');
        }

        return ['tree_oid' => $treeOid, 'diff_hash' => $diffHash];
    }
}
