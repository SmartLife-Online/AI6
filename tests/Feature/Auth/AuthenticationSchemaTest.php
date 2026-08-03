<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Models\UserSession;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

final class AuthenticationSchemaTest extends AuthFeatureTestCase
{
    public function test_migrations_create_the_complete_authentication_schema(): void
    {
        foreach (['users', 'projects', 'project_memberships', 'sessions'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }

        self::assertTrue(Schema::hasColumns('users', [
            'id', 'name', 'email', 'password', 'is_active', 'is_global_admin',
        ]));
        self::assertTrue(Schema::hasColumns('project_memberships', [
            'user_id', 'project_id', 'role',
        ]));
        self::assertTrue(Schema::hasColumns('sessions', [
            'id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity',
        ]));
    }

    public function test_email_and_membership_uniqueness_are_enforced_by_the_database(): void
    {
        $email = bin2hex(random_bytes(8)).'@example.test';
        $this->createUser(['email' => $email]);

        try {
            $this->createUser(['email' => $email]);
            self::fail('Expected duplicate email to be rejected.');
        } catch (QueryException) {
            self::assertDatabaseCount('users', 1);
        }

        $project = $this->createProject();
        $user = $this->createUser();
        $this->addMembership($user, $project, ProjectRole::ADMIN);

        try {
            ProjectMembership::query()->create([
                'user_id' => $user->getKey(),
                'project_id' => $project->getKey(),
                'role' => ProjectRole::VIEWER,
            ]);
            self::fail('Expected duplicate membership to be rejected.');
        } catch (QueryException) {
            self::assertDatabaseCount('project_memberships', 1);
        }
    }

    public function test_dependent_memberships_and_sessions_are_removed_on_delete(): void
    {
        $user = $this->createUser();
        $project = $this->createProject();
        $this->addMembership($user, $project);
        UserSession::query()->create([
            'id' => bin2hex(random_bytes(20)),
            'user_id' => $user->getKey(),
            'payload' => base64_encode(serialize([])),
            'last_activity' => time(),
        ]);

        $user->delete();

        self::assertDatabaseCount('project_memberships', 0);
        self::assertDatabaseCount('sessions', 0);

        $secondUser = $this->createUser();
        $secondProject = $this->createProject();
        $this->addMembership($secondUser, $secondProject);
        $secondProject->delete();

        self::assertDatabaseCount('project_memberships', 0);
    }
}
