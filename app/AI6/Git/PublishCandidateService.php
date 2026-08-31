<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketV1Parser;
use Throwable;

/** Reconstruct and bind the commit-free publish candidate through one private index. */
final readonly class PublishCandidateService
{
    public function __construct(
        private HardenedGitRunner $git,
        private RunTreeService $trees,
        private CandidateProvenancePreflight $preflight,
        private TicketV1Parser $tickets,
        private RunOrchestrator $runs,
    ) {}

    public function prospect(Run $run): PublishCandidate
    {
        $this->assertBindings($run);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'publish-candidate');
        $this->assertWorkspaceUnchanged($run, $context);

        $checkpoint = $this->trees->diff($run, $run->initial_run_base_sha, $run->checkpoint_commit_sha, $context);
        if (! hash_equals((string) $run->checkpoint_diff_hash, $checkpoint->hash)) {
            throw new PublishCandidateException('checkpoint_diff_binding_mismatch');
        }
        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        if (! $approval instanceof TicketApproval) {
            throw new PublishCandidateException('candidate_ticket_binding_missing');
        }
        // The checkpoint comparison starts at the immutable initial base, so it
        // deliberately contains later AI6-owned ticket status/contract updates.
        // Build the implementation delta from every other entry and apply it to
        // the latest run base; the candidate therefore keeps that base's exact
        // ticket bytes without treating the ticket mutation as agent output.
        $this->assertDiscardedTicketDeltaNeutral(
            $run,
            $approval->relative_path,
            $checkpoint->entries,
            $context,
        );
        $implementationEntries = array_values(array_filter(
            $checkpoint->entries,
            static fn (array $entry): bool => ! hash_equals($approval->relative_path, $entry['path']),
        ));

        $index = tempnam(sys_get_temp_dir(), 'ai6-candidate-');
        if (! is_string($index) || ! unlink($index)) {
            throw new PublishCandidateException('candidate_index_unavailable');
        }
        try {
            $tree = $this->git->writeCandidateTree(
                (string) $run->worktree_path,
                $run->run_base_sha,
                $implementationEntries,
                $index,
                $context,
            );
            $candidate = $this->trees->diff($run, $run->run_base_sha, $tree, $context);
            $this->assertTicketNeutral($run, $approval->relative_path, $tree, $context);
            $this->preflight->assertSafe($run, $tree, $candidate, $implementationEntries);

            return new PublishCandidate($tree, $candidate->hash, $run->run_base_sha);
        } catch (PublishCandidateException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PublishCandidateException('candidate_generation_failed');
        } finally {
            if (file_exists($index) || is_link($index)) {
                @unlink($index);
            }
        }
    }

    public function bind(Run $run, PublishCandidate $expected): Run
    {
        $actual = $this->prospect($run);
        if (! hash_equals($expected->treeOid, $actual->treeOid)
            || ! hash_equals($expected->diffHash, $actual->diffHash)
            || ! hash_equals($expected->baseSha, $actual->baseSha)) {
            throw new PublishCandidateException('candidate_expectation_mismatch');
        }

        return $this->runs->bindCandidate($run, $run->version, $actual);
    }

    private function assertBindings(Run $run): void
    {
        foreach (['initial_run_base_sha', 'run_base_sha', 'checkpoint_commit_sha', 'checkpoint_tree_sha', 'checkpoint_diff_hash'] as $field) {
            $value = $run->getAttribute($field);
            if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                throw new PublishCandidateException('candidate_binding_incomplete');
            }
        }
        $project = $run->project()->first();
        if ($project === null || ! is_string($project->control_oid)
            || ! hash_equals($run->run_base_sha, $project->control_oid)) {
            throw new PublishCandidateException('control_head_drift');
        }
        if (! $this->runs->hasEffectiveCheckpoint($run) || ! is_string($run->worktree_path)
            || ! is_string($run->run_branch)) {
            throw new PublishCandidateException('candidate_checkpoint_not_effective');
        }
    }

    private function assertWorkspaceUnchanged(Run $run, RedactionContext $context): void
    {
        $head = $this->git->resolveRunBranch($run->worktree_path, new RunBranchName($run->run_branch), $context);
        if (! $head->succeeded() || ! hash_equals((string) $run->checkpoint_commit_sha, trim($head->output))) {
            throw new PublishCandidateException('run_branch_outside_checkpoint_protocol');
        }
        $status = $this->git->workingTreeStatus($run->worktree_path, $context);
        if (! $status->succeeded() || $status->output !== '') {
            throw new PublishCandidateException('candidate_worktree_drift');
        }
    }

    private function assertTicketNeutral(Run $run, string $path, string $candidateTree, RedactionContext $context): void
    {
        try {
            $base = $this->git->readRegularBlob($run->worktree_path, $run->run_base_sha, $path, $context);
            $candidate = $this->git->readRegularBlob($run->worktree_path, $candidateTree, $path, $context);
            if (! hash_equals($base->blobSha, $candidate->blobSha) || ! hash_equals($base->content, $candidate->content)
                || ($this->tickets->parse($candidate->content)->frontmatter['status'] ?? null) !== 'in_progress') {
                throw new PublishCandidateException('candidate_ticket_not_neutral');
            }
        } catch (PublishCandidateException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PublishCandidateException('candidate_ticket_not_neutral');
        }
    }

    /**
     * A checkpoint may contain the AI6-owned ticket mutation, but filtering it
     * is safe only when its resulting blob is exactly the current run-base blob.
     * An agent-authored ticket change must never disappear from provenance.
     *
     * @param  list<array{old_mode: string, new_mode: string, old_oid: string, new_oid: string, status: string, path: string}>  $entries
     */
    private function assertDiscardedTicketDeltaNeutral(
        Run $run,
        string $path,
        array $entries,
        RedactionContext $context,
    ): void {
        $ticketEntries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => hash_equals($path, $entry['path']),
        ));
        if ($ticketEntries === []) {
            return;
        }
        if (count($ticketEntries) !== 1 || str_starts_with($ticketEntries[0]['status'], 'D')) {
            throw new PublishCandidateException('candidate_ticket_not_neutral');
        }
        try {
            $base = $this->git->readRegularBlob($run->worktree_path, $run->run_base_sha, $path, $context);
            if (! hash_equals($base->blobSha, $ticketEntries[0]['new_oid'])) {
                throw new PublishCandidateException('candidate_ticket_not_neutral');
            }
        } catch (PublishCandidateException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PublishCandidateException('candidate_ticket_not_neutral');
        }
    }
}
