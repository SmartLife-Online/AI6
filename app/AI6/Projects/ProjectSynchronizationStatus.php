<?php

namespace App\AI6\Projects;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use Illuminate\Support\Carbon;

final readonly class ProjectSynchronizationStatus
{
    public function __construct(private ControlOperationConfiguration $configuration) {}

    /** @return array{latestSynchronization: ControlOperation|null, controlUpdatedAt: Carbon|null, controlIsStale: bool} */
    public function for(Project $project): array
    {
        $types = [
            ControlOperationType::MANAGED_CLONE->value,
            ControlOperationType::MANAGED_FETCH->value,
        ];
        $latestQuery = ControlOperation::query();
        $latestQuery->with('result');
        $latestQuery->where('project_id', $project->getKey());
        $latestQuery->whereIn('operation_type', $types);
        $latestQuery->orderBy('created_at', 'desc');
        $latestQuery->limit(1);
        $latestSynchronization = $latestQuery->get()->first();

        $completedQuery = ControlOperation::query();
        $completedQuery->where('project_id', $project->getKey());
        $completedQuery->whereIn('operation_type', $types);
        $completedQuery->where('state', ControlOperationState::COMPLETED);
        $completedQuery->orderBy('completed_at', 'desc');
        $completedQuery->limit(1);
        $lastCompletedSynchronization = $completedQuery->get()->first();
        $controlUpdatedAt = $lastCompletedSynchronization?->completed_at;

        return [
            'latestSynchronization' => $latestSynchronization,
            'controlUpdatedAt' => $controlUpdatedAt,
            'controlIsStale' => $controlUpdatedAt === null
                || $controlUpdatedAt->lte(now()->subSeconds($this->configuration->staleSeconds)),
        ];
    }
}
