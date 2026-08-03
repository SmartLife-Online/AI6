<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;

final class SetProjectMembership
{
    public function handle(User $user, Project $project, ProjectRole $role): ProjectMembership
    {
        return ProjectMembership::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'project_id' => $project->getKey(),
            ],
            ['role' => $role],
        );
    }
}
