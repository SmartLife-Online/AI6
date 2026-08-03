<?php

namespace App\AI6\Auth\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;

final class RemoveProjectMembership
{
    public function handle(User $user, Project $project): void
    {
        ProjectMembership::query()
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->delete();
    }
}
