<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\Project;

final class ControlGeneration
{
    public function isCurrent(Project $project, int $recordedGeneration): bool
    {
        return $recordedGeneration >= 0
            && $recordedGeneration === $project->control_generation;
    }
}
