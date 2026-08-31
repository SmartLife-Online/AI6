<?php

namespace App\AI6\Runs;

use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;

/** The single report-only completion predicate. Findings are deliberately not inputs. */
final readonly class ReviewOnlyCompletionPredicate
{
    public function __construct(private RequiredReviewEvidence $reviewEvidence) {}

    public function decide(Run $run, ?string $resolvingRequestId = null): ReviewOnlyCompletionDecision
    {
        $blockers = [];
        if ($run->run_type !== RunType::REVIEW_ONLY) {
            $blockers[] = 'run_type_not_review_only';
        }
        if ($run->checkpoint_tree_sha === null || $run->checkpoint_diff_hash === null) {
            $blockers[] = 'review_checkpoint_missing';
        }
        if ($run->wait_reason instanceof WaitReason
            && ! ($resolvingRequestId !== null && in_array(
                $run->wait_reason,
                [WaitReason::MANUAL_REPORT, WaitReason::GIT_CONFLICT],
                true,
            ))) {
            $blockers[] = 'run_waiting:'.$run->wait_reason->value;
        }
        $openRequests = HumanRequest::query()->where('run_id', $run->id)
            ->where('resolution_state', HumanRequestResolutionState::OPEN->value);
        if ($resolvingRequestId !== null) {
            $openRequests->where('id', '<>', $resolvingRequestId);
        }
        if ($openRequests->exists()) {
            $blockers[] = 'human_request_open';
        }
        if (RunGate::query()->where('run_id', $run->id)->where('state', GateState::OPEN->value)->exists()) {
            $blockers[] = 'gate_open';
        }

        $blockers = [...$blockers, ...$this->reviewEvidence->blockers($run)];

        return new ReviewOnlyCompletionDecision(array_values(array_unique($blockers)));
    }
}
