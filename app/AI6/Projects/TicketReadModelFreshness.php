<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;

final readonly class TicketReadModelFreshness
{
    public function __construct(
        private ControlGeneration $controlGeneration,
        private EffectiveProjectConfiguration $projectConfiguration,
    ) {}

    /** @return array{stale: bool, reasons: list<string>} */
    public function for(
        Project $project,
        TicketReadModel $readModel,
        ?ProjectConfigurationBinding $binding = null,
    ): array {
        $reasons = [];
        if (! $this->controlGeneration->isCurrent($project, $readModel->control_generation)) {
            $reasons[] = 'control_generation_mismatch';
        }
        if ($project->control_oid === null || ! hash_equals($project->control_oid, $readModel->control_commit)) {
            $reasons[] = 'control_commit_mismatch';
        }
        $binding ??= $this->projectConfiguration->for($project);
        if (! is_string($readModel->validation_profile)
            || ! hash_equals($binding->configuration->ticketValidationProfile()->value, $readModel->validation_profile)) {
            $reasons[] = 'validation_profile_mismatch';
        }
        if (! is_string($readModel->effective_config_hash)
            || ! hash_equals($binding->configHash, $readModel->effective_config_hash)) {
            $reasons[] = 'effective_config_hash_mismatch';
        }

        return ['stale' => $reasons !== [], 'reasons' => $reasons];
    }
}
