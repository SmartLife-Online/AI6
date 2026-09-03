<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionResult;
use App\AI6\Shared\Redaction\Redactor;
use RuntimeException;

/**
 * Read-only diff, tree and blob access for the workspace of one bound run.
 *
 * Every entry point requires a bound run workspace and reads exclusively inside that workspace.
 * Blob bytes stay untrusted: they leave this service only through the central redaction boundary.
 */
final readonly class RunTreeService
{
    private const MAXIMUM_BLOB_BYTES = 1048576;

    public function __construct(
        private HardenedGitRunner $git,
        private CanonicalDiffHasher $diffs,
        private Redactor $redactor,
    ) {}

    /**
     * Compute the canonical difference between two bound object identifiers of this run.
     */
    public function diff(Run $run, string $fromOid, string $toOid, RedactionContext $context): CanonicalDiff
    {
        $raw = $this->git->canonicalRawDiff($this->repository($run), $fromOid, $toOid, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The bound run difference could not be resolved.');
        }

        return $this->diffs->fromRaw($raw->output, $context);
    }

    /**
     * The redacted patch text between two bound object identifiers of this run.
     * The raw bytes cross the central UTF-8/redaction boundary here, exactly once.
     */
    public function textualDiff(Run $run, string $fromOid, string $toOid, RedactionContext $context): string
    {
        $raw = $this->git->textualDiff($this->repository($run), $fromOid, $toOid, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The bound run patch text could not be resolved.');
        }

        return $this->redactor->redact($raw->output, $context)->text;
    }

    /** Compute the canonical difference from a bound commit to the complete staged worktree. */
    public function workingTreeDiff(Run $run, string $fromOid, RedactionContext $context): CanonicalDiff
    {
        $raw = $this->git->canonicalWorkingTreeDiff($this->repository($run), $fromOid, $context);
        if (! $raw->succeeded()) {
            throw new RuntimeException('The current run worktree difference could not be resolved.');
        }

        return $this->diffs->fromRaw($raw->output, $context);
    }

    /**
     * Inventory the direct children of the bound checkpoint tree.
     *
     * @return list<GitTreeEntry>
     */
    public function checkpointTree(Run $run, RedactionContext $context): array
    {
        if ($run->checkpoint_tree_sha === null) {
            throw new RuntimeException('The run has no bound checkpoint tree.');
        }

        return $this->children($run, $run->checkpoint_tree_sha, $context);
    }

    /**
     * Inventory the direct children of a tree object inside the bound run workspace.
     *
     * @return list<GitTreeEntry>
     */
    public function children(Run $run, string $treeOid, RedactionContext $context): array
    {
        return $this->git->listRunTreeEntries($this->repository($run), $treeOid, $context);
    }

    /**
     * Read a regular blob of the bound run workspace as central redaction output.
     */
    public function blob(Run $run, string $blobOid, RedactionContext $context): string
    {
        return $this->inspectBlob($run, $blobOid, $context)->text;
    }

    /** Inspect the real blob bytes through the central UTF-8/redaction boundary. */
    public function inspectBlob(Run $run, string $blobOid, RedactionContext $context): RedactionResult
    {
        $bytes = $this->git->readRunBlob($this->repository($run), $blobOid, self::MAXIMUM_BLOB_BYTES, $context);

        return $this->redactor->redact($bytes, $context);
    }

    private function repository(Run $run): string
    {
        if ($run->worktree_path === null) {
            throw new RuntimeException('The run has no bound workspace.');
        }

        return $run->worktree_path;
    }
}
