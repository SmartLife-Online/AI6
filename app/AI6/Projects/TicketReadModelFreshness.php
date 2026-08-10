<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;

final readonly class TicketReadModelFreshness
{
    public function __construct(private ControlGeneration $controlGeneration) {}

    /** @return array{stale: bool, reasons: list<string>} */
    public function for(Project $project, TicketReadModel $readModel): array
    {
        $reasons = [];
        if (! $this->controlGeneration->isCurrent($project, $readModel->control_generation)) {
            $reasons[] = 'control_generation_mismatch';
        }
        if ($project->control_oid === null || ! hash_equals($project->control_oid, $readModel->control_commit)) {
            $reasons[] = 'control_commit_mismatch';
        }

        return ['stale' => $reasons !== [], 'reasons' => $reasons];
    }
}
