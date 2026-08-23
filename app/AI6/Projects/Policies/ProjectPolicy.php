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
        'refresh_read_model' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => false,
        ],
        'edit_ticket' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => false,
        ],
        'change_ticket_status' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => true,
        ],
        'refresh_configuration' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => false,
        ],
        'approve_configuration' => [
            'admin' => false,
            'viewer' => false,
            'operator' => false,
            'approver' => true,
        ],
        'approve_ticket' => [
            'admin' => false,
            'viewer' => false,
            'operator' => false,
            'approver' => true,
        ],
        'authorize_gate_evidence' => [
            'admin' => false,
            'viewer' => false,
            'operator' => false,
            'approver' => true,
        ],
        'start_run' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => false,
        ],
        'view_run' => [
            'admin' => true,
            'viewer' => true,
            'operator' => true,
            'approver' => true,
        ],
        'answer_human_request' => [
            'admin' => true,
            'viewer' => false,
            'operator' => true,
            'approver' => true,
        ],
        'dispose_finding' => [
            'admin' => false,
            'viewer' => false,
            'operator' => false,
            'approver' => true,
        ],
    ];

    public function create(User $user): bool
    {
        return $user->is_active && $user->is_global_admin;
    }

    public function appearInList(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::APPEAR_IN_LIST, $user, $project);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::VIEW_DETAILS, $user, $project);
    }

    public function provisionDeployKey(User $user, Project $project): bool
    {
        return $user->is_active && $user->is_global_admin;
    }

    public function decideRecovery(User $user, Project $project): bool
    {
        return $user->is_active && $user->is_global_admin;
    }

    public function synchronizeManagedClone(User $user, Project $project): bool
    {
        return $user->is_active && $user->is_global_admin;
    }

    public function changeControlBranch(User $user, Project $project): bool
    {
        return $user->is_active && $user->is_global_admin;
    }

    public function refreshReadModel(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::REFRESH_READ_MODEL, $user, $project);
    }

    public function editTicket(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::EDIT_TICKET, $user, $project);
    }

    public function changeTicketStatus(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::CHANGE_TICKET_STATUS, $user, $project);
    }

    public function refreshConfiguration(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::REFRESH_CONFIGURATION, $user, $project);
    }

    public function approveConfiguration(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::APPROVE_CONFIGURATION, $user, $project);
    }

    public function approveTicket(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::APPROVE_TICKET, $user, $project);
    }

    public function startRun(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::START_RUN, $user, $project);
    }

    public function viewRun(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::VIEW_RUN, $user, $project);
    }

    public function answerHumanRequest(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::ANSWER_HUMAN_REQUEST, $user, $project);
    }

    public function disposeFinding(User $user, Project $project): bool
    {
        return $this->decide(ProjectAction::DISPOSE_FINDING, $user, $project);
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
