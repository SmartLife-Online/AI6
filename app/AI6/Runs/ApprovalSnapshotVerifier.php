<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\InstructionResolutionException;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Runs\Models\TicketApproval;
use InvalidArgumentException;

final readonly class ApprovalSnapshotVerifier
{
    public function __construct(
        private ApprovalSnapshotFactory $snapshots,
        private ApprovalSelectionFactory $selections,
    ) {}

    public function matches(TicketApproval $approval, Project $project, TicketReadModel $readModel): bool
    {
        try {
            $current = $this->snapshots->create(
                $project,
                $readModel,
                $this->selections->fromArray($approval->agent_profile_snapshot),
                $approval->snapshot_context_id,
            );
        } catch (AgentProfileSelectionException|InstructionResolutionException|PromptRenderingException|InvalidArgumentException) {
            return false;
        }

        return hash_equals($approval->approval_snapshot_hash, $current->aggregateHash);
    }
}
