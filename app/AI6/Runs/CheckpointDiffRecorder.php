<?php

namespace App\AI6\Runs;

use App\AI6\Git\RunTreeService;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Persist the redacted textual diff of the bound checkpoint as a run artifact
 * (UI-004, SEC-007, SEC-011).
 *
 * The worker computes the patch text over the hardened Git seam — from the
 * run base to the checkpoint commit, or from the review-subject base for a
 * review-only run — exactly once per bound checkpoint: the artifact carries
 * the checkpoint commit, tree and diff hash in its redacted metadata, and a
 * checkpoint that already has its artifact records nothing again. The text
 * crosses the central redaction once inside the Git seam and is persisted
 * without a second pass. The stored bytes open with a header line naming the
 * checkpoint commit, tree and diff hash: the store deduplicates by content,
 * and two checkpoints with the same patch text — an empty or an identically
 * cut one — must still resolve to their own artifacts, while the same
 * delivery stays idempotent. Header and patch text share the trusted
 * artifact budget: a diff over it is cut on a UTF-8 boundary and named as
 * truncated; a diff the seam cannot deliver — output limit, non-UTF-8 bytes
 * — is recorded as unavailable by reason, so the page never mistakes a
 * missing diff for an empty one.
 */
final readonly class CheckpointDiffRecorder
{
    /**
     * The largest header line a stored diff can open with — SHA-256 object
     * names for commit and tree plus the diff hash. RetentionPolicy refuses
     * an artifact budget below it, so every admitted budget can store the
     * checkpoint-bound representation of a diff, if only its header.
     */
    public const HEADER_MAX_BYTES = 234;

    public function __construct(
        private RunTreeService $trees,
        private RunArtifactStore $artifacts,
        private RetentionPolicy $retention,
    ) {}

    public function record(Run $run, RedactionContext $context): ?RunArtifact
    {
        $to = $run->checkpoint_commit_sha;
        $tree = $run->checkpoint_tree_sha;
        $diffHash = $run->checkpoint_diff_hash;
        $from = $run->run_type === RunType::REVIEW_ONLY ? $run->review_subject_base_sha : $run->initial_run_base_sha;
        if ($to === null || $tree === null || $diffHash === null || $from === null) {
            return null;
        }
        $existing = self::boundArtifact($run);
        if ($existing instanceof RunArtifact) {
            return $existing;
        }

        $metadata = [
            'kind' => RunArtifactKind::CHECKPOINT_DIFF->value,
            'checkpoint_commit_sha' => $to,
            'checkpoint_tree_sha' => $tree,
            'diff_hash' => $diffHash,
            'from_oid' => $from,
            'to_oid' => $to,
            'total_bytes' => 0,
            'truncated' => false,
            'unavailable' => null,
        ];
        $text = '';
        try {
            $text = $this->trees->textualDiff($run, $from, $to, $context);
        } catch (InvalidRedactionInputException) {
            $metadata['unavailable'] = 'not_utf8';
        } catch (RuntimeException) {
            $metadata['unavailable'] = 'git_output_unavailable';
        }
        $metadata['total_bytes'] = strlen($text);
        $header = self::header($to, $tree, $diffHash);
        $limit = $this->retention->artifactLimit(RunArtifactKind::CHECKPOINT_DIFF);
        if ($limit->exceeds(strlen($header) + strlen($text))) {
            $text = mb_strcut($text, 0, $limit->maxBytes - strlen($header), 'UTF-8');
            $metadata['truncated'] = true;
        }

        return $this->artifacts->persistEncoded($run, RunArtifactKind::CHECKPOINT_DIFF, $header.$text, $metadata, $context);
    }

    /** The first line of every stored diff: the binding the bytes carry themselves. */
    public static function header(string $commitSha, string $treeSha, string $diffHash): string
    {
        return '# AI6 checkpoint diff commit='.$commitSha.' tree='.$treeSha.' diff='.$diffHash."\n";
    }

    /** The diff artifact bound to the run's current checkpoint — its commit, tree and diff hash — if one exists. */
    public static function boundArtifact(Run $run): ?RunArtifact
    {
        /** @var Collection<int, RunArtifact> $candidates */
        $candidates = RunArtifact::query()->where('run_id', $run->getKey())
            ->where('kind', RunArtifactKind::CHECKPOINT_DIFF->value)
            ->orderByDesc('sequence')->get();
        // Commit, tree and diff hash together: two checkpoint commits can share
        // tree and diff hash, and each one owns the artifact bound to it.
        foreach ($candidates as $candidate) {
            if (($candidate->redacted_metadata['checkpoint_commit_sha'] ?? null) === $run->checkpoint_commit_sha
                && ($candidate->redacted_metadata['checkpoint_tree_sha'] ?? null) === $run->checkpoint_tree_sha
                && ($candidate->redacted_metadata['diff_hash'] ?? null) === $run->checkpoint_diff_hash) {
                return $candidate;
            }
        }

        return null;
    }
}
