<?php

namespace App\AI6\Projects;

use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final readonly class ProjectReadModelStatus
{
    public function __construct(
        private ControlOperationConfiguration $configuration,
        private TicketReadModelFreshness $freshness,
        private TicketReadModelUsePolicy $usePolicy,
    ) {}

    /** @return array{latestRefresh: ControlOperation|null, readModels: list<array{readModel: TicketReadModel, isStale: bool, staleReasons: list<string>, validationProfile: string|null, editorEligible: bool, approvalEligible: bool}>, refreshBasePath: string} */
    public function for(Project $project): array
    {
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
            ->map(function (TicketReadModel $readModel) use ($project): array {
                $freshness = $this->freshness->for($project, $readModel);

                return [
                    'readModel' => $readModel,
                    'isStale' => $freshness['stale'],
                    'staleReasons' => $freshness['reasons'],
                    'validationProfile' => $readModel->validation_profile,
                    'editorEligible' => $this->usePolicy->allowsEditor($readModel, ! $freshness['stale']),
                    'approvalEligible' => $this->usePolicy->allowsApproval($readModel, ! $freshness['stale']),
                ];
            })
            ->values()
            ->all();

        return [
            'latestRefresh' => $latestRefresh,
            'readModels' => $readModels,
            'refreshBasePath' => $this->configuration->refreshBasePath,
        ];
    }
}
