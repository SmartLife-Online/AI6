<?php

namespace App\AI6\Runs;

use App\AI6\HumanLoop\HumanRequestResolutionState;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Tickets\TicketV1Parser;

/** The single report-only completion predicate. Findings are deliberately not inputs. */
final readonly class ReviewOnlyCompletionPredicate
{
    public function __construct(private TicketV1Parser $tickets) {}

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

        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        $readModel = $approval instanceof TicketApproval
            ? TicketReadModel::query()->where('project_id', $run->project_id)
                ->where('relative_path', $approval->relative_path)
                ->where('ticket_contract_sha256', $run->ticket_contract_sha256 ?? $approval->ticket_contract_sha256)
                ->latest('generated_at')->first()
            : null;
        if (! $approval instanceof TicketApproval || ! $readModel instanceof TicketReadModel) {
            $blockers[] = 'approval_ticket_binding_missing';

            return new ReviewOnlyCompletionDecision(array_values(array_unique($blockers)));
        }
        try {
            $criterionIds = $this->tickets->parse($readModel->redacted_content)->acceptanceCriterionIds;
        } catch (\Throwable) {
            $blockers[] = 'ticket_contract_unreadable';
            $criterionIds = [];
        }

        $expected = [];
        // Verifier candidates are an approval-bound pool, not mandatory slots.
        // Slots are materialized only for findings, so requiring every candidate
        // here would make review-only completion permanently impossible.
        foreach (['reviewers' => 'quality_review'] as $key => $role) {
            $slots = ($approval->agent_profile_snapshot ?? [])[$key] ?? [];
            if (! is_array($slots)) {
                $blockers[] = $key.'_snapshot_invalid';

                continue;
            }
            foreach ($slots as $slot) {
                $id = is_array($slot) ? ($slot['id'] ?? null) : null;
                if (! is_string($id) || $id === '') {
                    $blockers[] = $key.'_slot_invalid';

                    continue;
                }
                $expected[$id] = $role;
            }
        }
        if ($expected === []) {
            $blockers[] = 'required_slots_missing';
        }

        $round = (int) ReviewResult::query()->where('run_id', $run->id)
            ->where('checkpoint_tree_sha', $run->checkpoint_tree_sha)
            ->where('diff_hash', $run->checkpoint_diff_hash)->max('round_number');
        if ($round < 1) {
            $blockers[] = 'review_round_missing';
        }
        foreach ($expected as $approvalSlotId => $role) {
            $slot = RunAgent::query()->where('run_id', $run->id)->where('role', $role)
                ->where('is_active', true)
                ->where(function ($query) use ($approvalSlotId): void {
                    $query->where('approval_slot_id', $approvalSlotId)->orWhere('slot_id', $approvalSlotId);
                })->first();
            if (! $slot instanceof RunAgent) {
                $blockers[] = 'slot_result_missing:'.$approvalSlotId;

                continue;
            }
            $result = ReviewResult::query()->where('run_id', $run->id)->where('round_number', $round)
                ->where('slot_id', $slot->slot_id)->where('checkpoint_tree_sha', $run->checkpoint_tree_sha)
                ->where('diff_hash', $run->checkpoint_diff_hash)
                ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT->value)->first();
            if (! $result instanceof ReviewResult) {
                $blockers[] = 'slot_result_missing:'.$approvalSlotId;

                continue;
            }
            $covered = CriterionCoverage::query()->where('review_result_id', $result->id)
                ->pluck('criterion_id')->map(static fn (mixed $id): string => (string) $id)->all();
            sort($covered, SORT_STRING);
            $required = $criterionIds;
            sort($required, SORT_STRING);
            if ($covered !== $required) {
                $blockers[] = 'criterion_coverage_incomplete:'.$approvalSlotId;
            }
        }

        return new ReviewOnlyCompletionDecision(array_values(array_unique($blockers)));
    }
}
