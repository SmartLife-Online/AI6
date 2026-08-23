<?php

namespace Tests\Unit\Auth;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\Policies\UserPolicy;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthorizationMatrixTest extends TestCase
{
    public function test_project_role_enum_and_action_enum_are_exact(): void
    {
        self::assertSame(
            ['admin', 'viewer', 'operator', 'approver'],
            array_map(static fn (ProjectRole $role): string => $role->value, ProjectRole::cases()),
        );
        self::assertSame(
            ['appear_in_list', 'view_details', 'refresh_read_model', 'edit_ticket', 'change_ticket_status', 'refresh_configuration', 'approve_configuration', 'approve_ticket', 'authorize_gate_evidence', 'start_run', 'view_run', 'answer_human_request', 'dispose_finding'],
            array_map(static fn (ProjectAction $action): string => $action->value, ProjectAction::cases()),
        );
    }

    public function test_project_policy_matches_the_complete_ticket_matrix(): void
    {
        $expected = ExpectedAuthorizationMatrix::projectActions();
        $policy = new ProjectPolicy;

        self::assertSame(
            $expected,
            (new ReflectionClass(ProjectPolicy::class))->getConstant('MATRIX'),
        );

        foreach (ProjectAction::cases() as $action) {
            foreach (ProjectRole::cases() as $role) {
                self::assertSame(
                    $expected[$action->value][$role->value],
                    $policy->decisionFor($action, $role),
                    $action->value.' / '.$role->value,
                );
            }
        }
    }

    public function test_all_seven_global_actions_require_an_active_global_administrator(): void
    {
        $policy = new UserPolicy;
        $globalAdministrator = (new User)->forceFill(['is_active' => true, 'is_global_admin' => true]);
        $projectMember = (new User)->forceFill(['is_active' => true, 'is_global_admin' => false]);
        $target = new User;
        $abilities = [
            'create' => static fn (User $actor): bool => $policy->create($actor),
            'deactivate' => static fn (User $actor): bool => $policy->deactivate($actor, $target),
            'delete' => static fn (User $actor): bool => $policy->delete($actor, $target),
            'grantGlobalAdministrator' => static fn (User $actor): bool => $policy->grantGlobalAdministrator($actor, $target),
            'revokeGlobalAdministrator' => static fn (User $actor): bool => $policy->revokeGlobalAdministrator($actor, $target),
            'setMembership' => static fn (User $actor): bool => $policy->setMembership($actor, $target),
            'removeMembership' => static fn (User $actor): bool => $policy->removeMembership($actor, $target),
        ];

        self::assertCount(7, ExpectedAuthorizationMatrix::globalActions());
        self::assertCount(7, $abilities);

        foreach ($abilities as $ability) {
            self::assertTrue($ability($globalAdministrator));
            self::assertFalse($ability($projectMember));
        }
    }
}
