<?php

namespace App\AI6\Projects;

use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final readonly class ProjectReadModelStatus
{
    public function __construct(
        private EffectiveProjectConfiguration $projectConfiguration,
        private TicketReadModelFreshness $freshness,
        private TicketReadModelUsePolicy $usePolicy,
    ) {}

    /** @return array{latestRefresh: ControlOperation|null, readModels: list<array{readModel: TicketReadModel, isStale: bool, staleReasons: list<string>, validationProfile: string|null, editorEligible: bool, approvalEligible: bool}>, refreshBasePath: string} */
    public function for(Project $project): array
    {
        $binding = $this->projectConfiguration->for($project);
        $latestRefresh = ControlOperation::query()
            ->with('result')
            ->where('project_id', $project->getKey())
            ->where('operation_type', ControlOperationType::TICKET_REFRESH)
            ->latest('created_at')
            ->first();
        $project->load([
            'ticketReadModels' => static function (HasMany $query): void {
                $query->select([
                    'id',
                    'project_id',
                    'relative_path',
                    'control_commit',
                    'blob_sha',
                    'control_generation',
                    'validation_profile',
                    'effective_config_hash',
                    'document_state',
                    'ticket_contract_sha256',
                    'redaction_state',
                    'source_blockers',
                    'approval_editor_eligible',
                    'editor_eligible',
                    'approval_eligible',
                    'generated_at',
                ])->orderBy('relative_path');
            },
        ]);
        $readModels = $project->ticketReadModels
            ->map(function (TicketReadModel $readModel) use ($project, $binding): array {
                $freshness = $this->freshness->for($project, $readModel, $binding);

                return [
                    'readModel' => $readModel,
                    'isStale' => $freshness['stale'],
                    'staleReasons' => $freshness['reasons'],
                    'validationProfile' => $readModel->validation_profile,
                    'editorEligible' => $this->usePolicy->allowsEditor($readModel, ! $freshness['stale'], $binding->configuration->ticketValidationProfile()),
                    'approvalEligible' => $this->usePolicy->allowsApproval($readModel, ! $freshness['stale'], $binding->configuration->ticketValidationProfile()),
                ];
            })
            ->values()
            ->all();

        return [
            'latestRefresh' => $latestRefresh,
            'readModels' => $readModels,
            'refreshBasePath' => $binding->configuration->ticketsPath(),
        ];
    }
}
