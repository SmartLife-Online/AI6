<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Shared\Config\ConfigurationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class QueueAutoStarter
{
    public function __construct(
        private EffectiveProjectConfiguration $configurations,
        private ApprovalQueue $queue,
        private ApprovalClaimStarter $starts,
        private QueueReevaluation $reevaluation,
    ) {}

    public function afterCompletion(Project $project, Run $completedRun): ?ControlOperation
    {
        try {
            return $this->attempt($project, $completedRun);
        } catch (ApprovalQueueConflict|RunTransitionConflict|ConfigurationException|ModelNotFoundException|QueryException $exception) {
            $this->recordRejection($project, $completedRun, $exception);

            return null;
        }
    }

    private function attempt(Project $project, Run $completedRun): ?ControlOperation
    {
        $project->refresh();
        if ($project->active_run_id !== null || $project->operation_lock_operation_id !== null
            || $project->pending_control_ref !== null || $project->pending_control_oid !== null
            || $project->pending_control_operation_id !== null) {
            return null;
        }

        $this->reevaluation->scheduleProject($project);
        $binding = $this->configurations->for($project);
        if (($binding->configuration->values['auto_start_next'] ?? false) !== true) {
            return null;
        }

        $actor = $this->completedRunStarter($project, $completedRun);
        if (! $actor instanceof User) {
            $this->recordRejection($project, $completedRun, new ModelNotFoundException);

            return null;
        }

        foreach ($this->queue->entries($project) as $approval) {
            if ($approval->queue_state !== ApprovalQueueState::QUEUED->value) {
                continue;
            }
            try {
                return $this->starts->start($actor, $project, $approval->getKey(), (string) Str::uuid(), automatic: true);
            } catch (ControlOperationConflict|AuthorizationException|ModelNotFoundException $exception) {
                $this->recordRejection($project, $completedRun, $exception);
            }
        }

        return null;
    }

    private function completedRunStarter(Project $project, Run $completedRun): ?User
    {
        if ($completedRun->project_id !== $project->getKey()
            || $completedRun->state !== RunState::COMPLETED) {
            return null;
        }
        $operation = ControlOperation::query()
            ->whereKey($completedRun->status_operation_id)
            ->where('project_id', $project->getKey())
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->first();

        return $operation?->actor()->first();
    }

    private function recordRejection(Project $project, Run $run, Throwable $exception): void
    {
        $reason = class_basename($exception);
        try {
            Log::warning('queue_auto_start_rejected', [
                'project_id' => $project->getKey(),
                'run_id' => $run->getKey(),
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            // Telemetry must not turn an already durable completion into a failure.
        }
        try {
            RunEvent::query()->firstOrCreate(
                ['event_key' => 'queue-auto-start-rejected:'.$run->getKey().':'.$reason],
                [
                    'run_id' => $run->getKey(),
                    'event_type' => 'queue_auto_start_rejected',
                    'redacted_payload' => 'Der automatische Folgestart wurde benannt abgewiesen: '.$reason.'.',
                ],
            );
        } catch (Throwable) {
            // The completion boundary is stronger than follow-up telemetry.
        }
    }
}
