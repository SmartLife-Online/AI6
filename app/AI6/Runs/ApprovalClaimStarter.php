<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Support\Facades\DB;

final readonly class ApprovalClaimStarter
{
    public function __construct(
        private QueueEligibility $eligibility,
        private QueueRunStart $starts,
    ) {}

    public function start(User $actor, Project $project, string $approvalId, string $operationId, bool $automatic = false): ControlOperation
    {
        return DB::transaction(function () use ($actor, $project, $approvalId, $operationId, $automatic): ControlOperation {
            DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->first();
            $project = Project::query()->findOrFail($project->getKey());
            DB::table('ticket_approvals')
                ->where('id', $approvalId)
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $approval = TicketApproval::query()->findOrFail($approvalId);
            $decision = $this->eligibility->resolve($approval, $project);
            if (! $decision->eligible || $decision->readModel === null) {
                throw new ControlOperationConflict('The approval is not eligible: '.implode(',', $decision->reasons));
            }

            return $this->starts->handleVerified($actor, $project, $approval, $decision->readModel, $operationId, $automatic);
        });
    }
}
