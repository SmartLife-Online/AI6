<?php

namespace App\AI6\Git;

use App\AI6\Git\Jobs\ExecuteControlOperation;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class ControlOperationReconciler
{
    public function __construct(
        private ControlOperationConfiguration $configuration,
        private ProjectOperationLease $lease,
    ) {}

    public function reconcile(): int
    {
        $handled = $this->releaseTerminalLeases();
        $threshold = Date::now()->subSeconds($this->configuration->reconcilerSeconds);
        $expiredProjectIds = Project::query()
            ->where(function ($query): void {
                $query->whereNull('operation_lock_operation_id')
                    ->orWhereNull('operation_lock_lease_expires_at')
                    ->orWhere('operation_lock_lease_expires_at', '<=', Date::now());
            })
            ->pluck('id');

        $operations = ControlOperation::query()
            ->where(function ($query) use ($threshold, $expiredProjectIds): void {
                $query->where(function ($queued) use ($threshold): void {
                    $queued->where('state', ControlOperationState::QUEUED)
                        ->where('updated_at', '<=', $threshold);
                })->orWhere(function ($running) use ($expiredProjectIds): void {
                    $running->where('state', ControlOperationState::RUNNING)
                        ->whereIn('project_id', $expiredProjectIds);
                })->orWhere(function ($recovery) use ($threshold): void {
                    $recovery->where('state', ControlOperationState::RECOVERY_REQUIRED)
                        ->where(function ($decision) use ($threshold): void {
                            $decision->whereHas('recoveryDecision')
                                ->orWhere('updated_at', '<=', $threshold);
                        });
                });
            })
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $requeued = 0;
        foreach ($operations as $operation) {
            $queued = DB::transaction(function () use ($operation, $threshold): bool {
                $current = ControlOperation::query()->find($operation->id);
                if (! $current instanceof ControlOperation
                    || ! $this->eligible($current, $threshold)
                    || $this->hasRunnableJob($current->id)) {
                    return false;
                }

                $failed = DB::table('failed_jobs')
                    ->where('payload', 'like', '%ExecuteControlOperation%')
                    ->where('payload', 'like', '%'.$current->id.'%')
                    ->exists();
                if ($failed) {
                    ControlOperation::query()
                        ->whereKey($current->id)
                        ->whereNotIn('state', [
                            ControlOperationState::COMPLETED->value,
                            ControlOperationState::FAILED->value,
                            ControlOperationState::ABANDONED->value,
                        ])
                        ->update([
                            'last_error' => 'Der ausgeschöpfte Queue-Auftrag der Control-Operation wurde erneut zugestellt.',
                            'version' => DB::raw('version + 1'),
                            'updated_at' => Date::now(),
                        ]);
                }

                Queue::connection('database')->push(new ExecuteControlOperation($current->id));

                return true;
            });
            if ($queued) {
                $requeued++;
            }
        }

        return $handled + $requeued;
    }

    private function releaseTerminalLeases(): int
    {
        $operationIds = DB::table('control_operations')
            ->join('projects', function (JoinClause $join): void {
                $join->on('projects.id', '=', 'control_operations.project_id')
                    ->on('projects.operation_lock_operation_id', '=', 'control_operations.id')
                    ->on('projects.operation_lock_attempt_token', '=', 'control_operations.current_attempt_token');
            })
            ->whereIn('control_operations.state', [
                ControlOperationState::COMPLETED->value,
                ControlOperationState::FAILED->value,
                ControlOperationState::ABANDONED->value,
            ])
            ->whereNotNull('control_operations.current_attempt_token')
            ->limit(100)
            ->pluck('control_operations.id');
        $operations = ControlOperation::query()->findMany($operationIds);

        $released = 0;
        foreach ($operations as $operation) {
            if ($operation->current_attempt_token !== null
                && $this->lease->releaseTerminal($operation, $operation->current_attempt_token)) {
                $released++;
            }
        }

        return $released;
    }

    private function eligible(ControlOperation $operation, \DateTimeInterface $threshold): bool
    {
        if ($operation->state === ControlOperationState::QUEUED) {
            return $operation->updated_at !== null && $operation->updated_at <= $threshold;
        }

        if ($operation->state === ControlOperationState::RUNNING) {
            // Re-checked here, inside this operation's own transaction, instead of against the
            // pre-loop snapshot: a heartbeat can renew the project's lease while earlier operations
            // in this batch are still being processed, and that renewal must be honored immediately
            // rather than causing a spurious requeue of a lease that is no longer expired.
            return $this->projectLeaseExpired($operation->project_id);
        }

        return $operation->state === ControlOperationState::RECOVERY_REQUIRED
            && ($operation->recoveryDecision()->exists()
                || ($operation->updated_at !== null && $operation->updated_at <= $threshold));
    }

    private function projectLeaseExpired(int $projectId): bool
    {
        $project = Project::query()->find($projectId);

        return ! $project instanceof Project
            || $project->operation_lock_operation_id === null
            || $project->operation_lock_lease_expires_at === null
            || $project->operation_lock_lease_expires_at->lte(Date::now());
    }

    private function hasRunnableJob(string $operationId): bool
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ExecuteControlOperation%')
            ->where('payload', 'like', '%'.$operationId.'%')
            ->exists();
    }
}
