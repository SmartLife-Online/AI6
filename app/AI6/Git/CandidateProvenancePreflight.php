<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Runs\ScopePathMatcher;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;

/** Deterministic, non-LLM provenance and secret gate over the actual candidate tree. */
final readonly class CandidateProvenancePreflight
{
    public function __construct(private RunTreeService $trees) {}

    /** @param list<array{old_mode: string, new_mode: string, old_oid: string, new_oid: string, status: string, path: string}> $checkpointEntries */
    public function assertSafe(Run $run, string $candidateTree, CanonicalDiff $candidate, array $checkpointEntries): void
    {
        $scope = $run->effective_scope_snapshot
            ?? array_values(array_filter(($run->scope_snapshot ?? [])['ticket_files'] ?? [], 'is_string'));
        $expected = [];
        foreach ($checkpointEntries as $entry) {
            $expected[$entry['path']] = $entry;
        }

        if (array_keys($expected) !== array_column($candidate->entries, 'path')) {
            throw new PublishCandidateException('candidate_change_not_from_checkpoint');
        }

        $context = new RedactionContext((string) $run->project_id, $run->id, 'candidate-provenance');
        foreach ($candidate->entries as $entry) {
            $path = $entry['path'];
            $bound = $expected[$path] ?? null;
            if (! is_array($bound)
                || $entry['status'] !== $bound['status']
                || $entry['old_mode'] !== $bound['old_mode']
                || $entry['new_mode'] !== $bound['new_mode']
                || $entry['old_oid'] !== $bound['old_oid']
                || $entry['new_oid'] !== $bound['new_oid']) {
                throw new PublishCandidateException('candidate_change_not_from_checkpoint');
            }
            if (! ScopePathMatcher::coveredBy($path, $scope)) {
                throw new PublishCandidateException('candidate_path_outside_effective_scope');
            }
            if (str_starts_with($entry['status'], 'D')) {
                continue;
            }
            if (! in_array($entry['new_mode'], ['100644', '100755'], true)) {
                throw new PublishCandidateException(match ($entry['new_mode']) {
                    '120000' => 'candidate_symlink_forbidden',
                    '160000' => 'candidate_gitlink_forbidden',
                    default => 'candidate_blob_mode_forbidden',
                });
            }
            $leaf = $this->leaf($run, $candidateTree, $path, $context);
            if (! $leaf instanceof GitTreeEntry || $leaf->type !== 'blob'
                || $leaf->mode !== $entry['new_mode'] || $leaf->objectId !== $entry['new_oid']) {
                throw new PublishCandidateException('candidate_tree_entry_mismatch');
            }
            try {
                if (count($this->trees->inspectBlob($run, $leaf->objectId, $context)->matches) > 0) {
                    throw new PublishCandidateException('candidate_secret_detected');
                }
            } catch (RunBlobSizeLimitExceeded) {
                throw new PublishCandidateException('candidate_blob_too_large');
            } catch (InvalidRedactionInputException) {
                throw new PublishCandidateException('candidate_blob_not_utf8');
            }
        }
    }

    private function leaf(Run $run, string $treeOid, string $path, RedactionContext $context): ?GitTreeEntry
    {
        $segments = explode('/', $path);
        if (count($segments) > 128) {
            throw new PublishCandidateException('candidate_path_invalid');
        }
        $current = $treeOid;
        foreach ($segments as $index => $segment) {
            $entry = null;
            foreach ($this->trees->children($run, $current, $context) as $child) {
                if (hash_equals($segment, $child->name)) {
                    $entry = $child;
                    break;
                }
            }
            if (! $entry instanceof GitTreeEntry) {
                return null;
            }
            if ($index === array_key_last($segments)) {
                return $entry;
            }
            if ($entry->type !== 'tree') {
                return null;
            }
            $current = $entry->objectId;
        }

        return null;
    }
}
