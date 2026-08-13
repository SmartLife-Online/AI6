<?php

namespace App\AI6\Projects;

use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\Models\ProjectConfigSnapshot;

final readonly class ProjectConfigurationStatus
{
    public function __construct(
        private EffectiveProjectConfiguration $effective,
        private ProjectConfigurationDiff $diff,
    ) {}

    /**
     * @return array{
     *     binding: ProjectConfigurationBinding,
     *     draft: ProjectConfigDraft|null,
     *     latestSnapshot: ProjectConfigSnapshot|null,
     *     latestRefresh: ControlOperation|null,
     *     changes: list<array{path: string, before: mixed, after: mixed}>
     * }
     */
    public function for(Project $project): array
    {
        $binding = $this->effective->for($project);
        $draft = ProjectConfigDraft::query()->where('project_id', $project->getKey())->latest('created_at')->latest('id')->first();
        $latestSnapshot = ProjectConfigSnapshot::query()->where('project_id', $project->getKey())
            ->latest('approved_at')->latest('id')->first();
        $latestRefresh = ControlOperation::query()->where('project_id', $project->getKey())
            ->where('operation_type', ControlOperationType::CONFIG_REFRESH)->latest('created_at')->first();
        $changes = [];
        if ($draft instanceof ProjectConfigDraft && $draft->state === 'valid' && is_array($draft->normalized_config)) {
            $before = $latestSnapshot instanceof ProjectConfigSnapshot
                ? new ProjectConfiguration($latestSnapshot->normalized_config)
                : $binding->configuration;
            $changes = $this->diff->between($before, new ProjectConfiguration($draft->normalized_config));
        }

        return compact('binding', 'draft', 'latestSnapshot', 'latestRefresh', 'changes');
    }
}
