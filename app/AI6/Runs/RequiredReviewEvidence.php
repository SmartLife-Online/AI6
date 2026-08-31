<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Tickets\TicketV1Parser;
use Throwable;

/** The shared required-slot and exact AC-coverage proof for every completion gate. */
final readonly class RequiredReviewEvidence
{
    public function __construct(private TicketV1Parser $tickets) {}

    /** @return list<string> */
    public function blockers(Run $run): array
    {
        $blockers = [];
        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        $readModel = $approval instanceof TicketApproval
            ? TicketReadModel::query()->where('project_id', $run->project_id)
                ->where('relative_path', $approval->relative_path)
                ->where('ticket_contract_sha256', $run->ticket_contract_sha256 ?? $approval->ticket_contract_sha256)
                ->latest('generated_at')->first()
            : null;
        if (! $approval instanceof TicketApproval || ! $readModel instanceof TicketReadModel) {
            return ['approval_ticket_binding_missing'];
        }
        try {
            $criterionIds = $this->tickets->parse($readModel->redacted_content)->acceptanceCriterionIds;
        } catch (Throwable) {
            return ['ticket_contract_unreadable'];
        }

        $expected = [];
        $slots = ($approval->agent_profile_snapshot ?? [])['reviewers'] ?? null;
        if (! is_array($slots)) {
            $blockers[] = 'reviewers_snapshot_invalid';
        } else {
            foreach ($slots as $slot) {
                $id = is_array($slot) ? ($slot['id'] ?? null) : null;
                if (! is_string($id) || $id === '') {
                    $blockers[] = 'reviewers_slot_invalid';
                } else {
                    $expected[] = $id;
                }
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
        foreach ($expected as $approvalSlotId) {
            $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'quality_review')
                ->where('is_active', true)
                ->where(function ($query) use ($approvalSlotId): void {
                    $query->where('approval_slot_id', $approvalSlotId)->orWhere('slot_id', $approvalSlotId);
                })->first();
            if (! $slot instanceof RunAgent) {
                $blockers[] = 'slot_result_missing:'.$approvalSlotId;

                continue;
            }
            $result = ReviewResult::query()->where('run_id', $run->id)->where('round_number', $round)
                ->where('slot_id', $slot->slot_id)->where('checkpoint_commit_sha', $run->checkpoint_commit_sha)
                ->where('checkpoint_tree_sha', $run->checkpoint_tree_sha)->where('diff_hash', $run->checkpoint_diff_hash)
                ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT->value)->first();
            if (! $result instanceof ReviewResult || ! $this->bindingsMatch($result, $run, $approval)) {
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

        return array_values(array_unique($blockers));
    }

    private function bindingsMatch(ReviewResult $result, Run $run, TicketApproval $approval): bool
    {
        foreach ([
            'approval_config_hash' => 'config_hash',
            'approval_scope_hash' => 'scope_hash',
            'approval_prompt_hash' => 'prompt_hash',
            'approval_instruction_hash' => 'instruction_hash',
            'approval_runtime_profile_hash' => 'runtime_profile_hash',
            'approval_agent_profile_hash' => 'agent_profile_hash',
            'approval_security_policy_hash' => 'security_policy_hash',
        ] as $resultField => $runField) {
            $left = $result->getAttribute($resultField);
            $right = $run->getAttribute($runField);
            if (! is_string($left) || ! is_string($right) || ! hash_equals($left, $right)) {
                return false;
            }
        }

        $snapshot = $result->getAttribute('approval_snapshot_hash');

        return is_string($snapshot) && hash_equals($snapshot, $approval->approval_snapshot_hash);
    }
}
