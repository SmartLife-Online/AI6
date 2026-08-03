<?php

namespace App\AI6\Projects\Policies;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use LogicException;

final class ProjectPolicy
{
    /** @var array<string, array<string, bool>> */
    private const MATRIX = [
        'appear_in_list' => [
            'admin' => true,
            'viewer' => true,
            'operator' => true,
            'approver' => true,
        ],
        'view_details' => [
            'admin' => true,
            'viewer' => true,
            'operator' => true,
            'approver' => true,
        ],
    ];

    public function appearInList(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::APPEAR_IN_LIST, $user, $project);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::VIEW_DETAILS, $user, $project);
    }

    public function decide(ProjectAction $action, User $user, Project $project): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $membership = ProjectMembership::query()
            ->where('user_id', $user->getKey())
            ->where('project_id', $project->getKey())
            ->first();

        if (! $membership instanceof ProjectMembership) {
            return false;
        }

        $role = $membership->role;

        return $this->decisionFor($action, $role);
    }

    public function decisionFor(ProjectAction $action, ProjectRole $role): bool
    {
        $matrix = $this->matrix();

        if (! array_key_exists($action->value, $matrix)
            || ! array_key_exists($role->value, $matrix[$action->value])) {
            throw new LogicException(sprintf(
                'Project authorization matrix is incomplete for action %s and role %s.',
                $action->value,
                $role->value,
            ));
        }

        return $matrix[$action->value][$role->value];
    }

    /** @return array<string, array<string, bool>> */
    private function matrix(): array
    {
        return self::MATRIX;
    }
}
