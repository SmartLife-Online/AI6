<?php

namespace App\AI6\Runs\Jobs;

use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\QueueEligibility;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class EvaluateTicketApproval implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $evaluationId)
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(
        ControlOperationRuntimeIdentity $runtimeIdentity,
        QueueEligibility $eligibility,
    ): void {
        if ($runtimeIdentity->runtimeRole !== 'worker') {
            throw new RuntimeException('Approval evaluations may execute only in the worker role.');
        }
        $evaluation = TicketApprovalEvaluation::query()->findOrFail($this->evaluationId);
        if ($evaluation->state !== 'queued') {
            return;
        }
        try {
            $approval = TicketApproval::query()->findOrFail($evaluation->ticket_approval_id);
            $project = Project::query()->findOrFail($approval->project_id);
            $decision = $eligibility->decide($approval, $project);
            TicketApprovalEvaluation::query()
                ->whereKey($evaluation->id)
                ->where('state', 'queued')
                ->update([
                    'state' => 'ready',
                    'eligible' => $decision['eligible'],
                    'reasons' => $decision['reasons'],
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            TicketApprovalEvaluation::query()
                ->whereKey($evaluation->id)
                ->where('state', 'queued')
                ->update([
                    'state' => 'conflict',
                    'eligible' => false,
                    'reasons' => ['approval_evaluation_failed'],
                    'updated_at' => now(),
                ]);
        }
    }
}
