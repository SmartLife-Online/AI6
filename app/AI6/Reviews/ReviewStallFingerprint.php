<?php

namespace App\AI6\Reviews;

use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;

/** Canonical fingerprint of one fully completed review round. */
final class ReviewStallFingerprint
{
    /** @param list<string> $duplicateGroups */
    public function fromGroups(array $duplicateGroups, string $diffHash): string
    {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $diffHash) !== 1) {
            throw new ReviewResultParseException('stall_diff_hash_invalid');
        }
        foreach ($duplicateGroups as $group) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $group) !== 1) {
                throw new ReviewResultParseException('stall_group_invalid');
            }
        }
        $groups = array_values(array_unique($duplicateGroups));
        sort($groups, SORT_STRING);

        return hash('sha256', "AI6-REVIEW-STALL-V1\0".$diffHash."\0".implode("\n", $groups));
    }

    public function completedRound(Run $run, int $round): ?string
    {
        if ($round < 1) {
            return null;
        }
        $reviewers = $run->agent_profile_snapshot['reviewers'] ?? null;
        if (! is_array($reviewers) || $reviewers === []) {
            return null;
        }
        $completed = ReviewResult::query()->where('run_id', $run->id)
            ->where('round_number', $round)
            ->where('role', 'quality_review')
            ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT)
            ->distinct()->pluck('slot_id')->map(static fn (mixed $id): string => (string) $id)->all();
        // A reviewer switch inside a round leaves valid results of two slot
        // revisions for the same approval slot; the round is complete once
        // every approval slot delivered one, not once every revision did.
        $approvalSlots = RunAgent::query()->where('run_id', $run->id)->get()
            ->filter(static fn (RunAgent $agent): bool => in_array($agent->slot_id, $completed, true))
            ->map(static fn (RunAgent $agent): string => (string) ($agent->approval_slot_id ?? $agent->slot_id))
            ->unique();
        if ($approvalSlots->count() !== count($reviewers)) {
            return null;
        }

        $diffHash = ReviewResult::query()->where('run_id', $run->id)
            ->where('round_number', $round)
            ->where('role', 'quality_review')
            ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT)
            ->value('diff_hash');
        if (! is_string($diffHash)) {
            return null;
        }
        $groups = Finding::query()->where('run_id', $run->id)->where('round_number', $round)
            ->pluck('duplicate_group')->map(static fn (mixed $group): string => (string) $group)->all();

        return $this->fromGroups($groups, $diffHash);
    }

    public function stalled(Run $run, int $round): bool
    {
        if ($round < 2) {
            return false;
        }
        $previous = $this->completedRound($run, $round - 1);
        $current = $this->completedRound($run, $round);

        return is_string($previous) && is_string($current) && hash_equals($previous, $current);
    }
}
