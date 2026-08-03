<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\ProjectRole;
use Tests\Unit\Auth\ExpectedAuthorizationMatrix;

final class AdministrationAuthorizationTest extends AuthFeatureTestCase
{
    public function test_global_administrator_creates_users_with_normalized_unique_email_addresses(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);

        $this->actingAs($administrator)
            ->postJson(route('admin.users.create'), [
                'name' => 'Normalisierter Benutzer',
                'email' => '  New.User@Example.Test  ',
                'password' => bin2hex(random_bytes(16)),
            ])
            ->assertCreated()
            ->assertJsonPath('email', 'new.user@example.test');

        $this->actingAs($administrator)
            ->postJson(route('admin.users.create'), [
                'name' => 'Doppelter Benutzer',
                'email' => 'NEW.USER@EXAMPLE.TEST',
                'password' => bin2hex(random_bytes(16)),
            ])
            ->assertUnprocessable();

        self::assertSame(1, User::query()->where('email', 'new.user@example.test')->count());
    }

    public function test_each_project_role_is_denied_each_of_the_seven_global_actions(): void
    {
        self::assertCount(7, ExpectedAuthorizationMatrix::globalActions());

        foreach (ProjectRole::cases() as $role) {
            $actor = $this->createUser();
            $actorProject = $this->createProject();
            $this->addMembership($actor, $actorProject, $role);

            $beforeUsers = User::query()->count();
            $this->actingAs($actor)
                ->postJson(route('admin.users.create'), [
                    'name' => 'Nicht erlaubt',
                    'email' => bin2hex(random_bytes(8)).'@example.test',
                    'password' => bin2hex(random_bytes(16)),
                ])
                ->assertForbidden();
            self::assertSame($beforeUsers, User::query()->count());

            $deactivateTarget = $this->createUser();
            $this->actingAs($actor)
                ->patchJson(route('admin.users.deactivate', $deactivateTarget))
                ->assertForbidden();
            self::assertTrue($deactivateTarget->fresh()->is_active);

            $deleteTarget = $this->createUser();
            $this->actingAs($actor)
                ->deleteJson(route('admin.users.delete', $deleteTarget))
                ->assertForbidden();
            self::assertTrue($deleteTarget->fresh()->exists);

            $grantTarget = $this->createUser();
            $this->actingAs($actor)
                ->putJson(route('admin.users.global-administrator.grant', $grantTarget))
                ->assertForbidden();
            self::assertFalse($grantTarget->fresh()->is_global_admin);

            $revokeTarget = $this->createUser(['is_global_admin' => true]);
            $this->actingAs($actor)
                ->deleteJson(route('admin.users.global-administrator.revoke', $revokeTarget))
                ->assertForbidden();
            self::assertTrue($revokeTarget->fresh()->is_global_admin);

            $membershipTarget = $this->createUser();
            $membershipProject = $this->createProject();
            $this->actingAs($actor)
                ->putJson(route('admin.users.memberships.set', [$membershipTarget, $membershipProject]), [
                    'role' => ProjectRole::OPERATOR->value,
                ])
                ->assertForbidden();
            self::assertDatabaseMissing('project_memberships', [
                'user_id' => $membershipTarget->getKey(),
                'project_id' => $membershipProject->getKey(),
            ]);

            $this->addMembership($membershipTarget, $membershipProject, ProjectRole::VIEWER);
            $this->actingAs($actor)
                ->deleteJson(route('admin.users.memberships.remove', [$membershipTarget, $membershipProject]))
                ->assertForbidden();
            self::assertDatabaseHas('project_memberships', [
                'user_id' => $membershipTarget->getKey(),
                'project_id' => $membershipProject->getKey(),
            ]);
        }
    }

    public function test_global_administrator_without_membership_can_execute_all_seven_actions(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);

        $this->actingAs($administrator)
            ->postJson(route('admin.users.create'), [
                'name' => 'Angelegter Benutzer',
                'email' => 'created@example.test',
                'password' => bin2hex(random_bytes(16)),
            ])
            ->assertCreated();
        self::assertDatabaseHas('users', ['email' => 'created@example.test']);

        $deactivateTarget = $this->createUser();
        $this->actingAs($administrator)
            ->patchJson(route('admin.users.deactivate', $deactivateTarget))
            ->assertNoContent();
        self::assertFalse($deactivateTarget->fresh()->is_active);

        $deleteTarget = $this->createUser();
        $this->actingAs($administrator)
            ->deleteJson(route('admin.users.delete', $deleteTarget))
            ->assertNoContent();
        self::assertNull($deleteTarget->fresh());

        $grantTarget = $this->createUser();
        $this->actingAs($administrator)
            ->putJson(route('admin.users.global-administrator.grant', $grantTarget))
            ->assertNoContent();
        self::assertTrue($grantTarget->fresh()->is_global_admin);

        $revokeTarget = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($administrator)
            ->deleteJson(route('admin.users.global-administrator.revoke', $revokeTarget))
            ->assertNoContent();
        self::assertFalse($revokeTarget->fresh()->is_global_admin);

        $membershipTarget = $this->createUser();
        $project = $this->createProject();
        $this->actingAs($administrator)
            ->putJson(route('admin.users.memberships.set', [$membershipTarget, $project]), [
                'role' => ProjectRole::APPROVER->value,
            ])
            ->assertNoContent();
        self::assertDatabaseHas('project_memberships', [
            'user_id' => $membershipTarget->getKey(),
            'project_id' => $project->getKey(),
            'role' => ProjectRole::APPROVER->value,
        ]);

        $this->actingAs($administrator)
            ->deleteJson(route('admin.users.memberships.remove', [$membershipTarget, $project]))
            ->assertNoContent();
        self::assertDatabaseMissing('project_memberships', [
            'user_id' => $membershipTarget->getKey(),
            'project_id' => $project->getKey(),
        ]);

        $this->actingAs($administrator)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee($project->name);
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertForbidden();
    }

    public function test_last_active_global_administrator_is_protected_and_same_actions_succeed_with_a_second(): void
    {
        $lastAdministrator = $this->createUser(['is_global_admin' => true]);

        $this->actingAs($lastAdministrator)
            ->patchJson(route('admin.users.deactivate', $lastAdministrator))
            ->assertStatus(409);
        self::assertTrue($lastAdministrator->fresh()->is_active);

        $this->actingAs($lastAdministrator)
            ->deleteJson(route('admin.users.delete', $lastAdministrator))
            ->assertStatus(409);
        self::assertNotNull($lastAdministrator->fresh());

        $this->actingAs($lastAdministrator)
            ->deleteJson(route('admin.users.global-administrator.revoke', $lastAdministrator))
            ->assertStatus(409);
        self::assertTrue($lastAdministrator->fresh()->is_global_admin);

        $keeper = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($keeper)
            ->patchJson(route('admin.users.deactivate', $lastAdministrator))
            ->assertNoContent();
        self::assertFalse($lastAdministrator->fresh()->is_active);

        $deleteTarget = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($keeper)
            ->deleteJson(route('admin.users.delete', $deleteTarget))
            ->assertNoContent();
        self::assertNull($deleteTarget->fresh());

        $revokeTarget = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($keeper)
            ->deleteJson(route('admin.users.global-administrator.revoke', $revokeTarget))
            ->assertNoContent();
        self::assertFalse($revokeTarget->fresh()->is_global_admin);
    }
}
