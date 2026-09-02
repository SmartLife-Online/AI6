<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

final readonly class ProjectQueueController
{
    public function index(Project $project, ApprovalQueue $queue, QueueEligibility $eligibility): View
    {
        $entries = [];
        $nextStartable = null;

        foreach ($queue->entries($project) as $position => $approval) {
            $evaluationId = DB::table('ticket_approval_evaluations')
                ->where('ticket_approval_id', $approval->getKey())
                ->latest('updated_at')
                ->latest('id')
                ->value('id');
            $evaluation = is_string($evaluationId)
                ? TicketApprovalEvaluation::query()->find($evaluationId)
                : null;
            $decision = $eligibility->storedDecision($approval, $evaluation);
            $entry = [
                'position' => $position + 1,
                'approval' => $approval,
                'eligible' => $decision['eligible'],
                'reasons' => $decision['reasons'],
            ];
            $entries[] = $entry;
            if ($nextStartable === null && $approval->queue_state === ApprovalQueueState::QUEUED->value && $decision['eligible']) {
                $nextStartable = $approval;
            }
        }

        return view('queue.index', compact('project', 'entries', 'nextStartable'));
    }
}
