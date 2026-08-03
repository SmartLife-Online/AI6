<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class AuthFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'database',
        ]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
    }

    /** @param array<string, mixed> $attributes */
    protected function createUser(array $attributes = []): User
    {
        return User::query()->create(array_replace([
            'name' => 'Benutzer '.bin2hex(random_bytes(4)),
            'email' => bin2hex(random_bytes(8)).'@example.test',
            'password' => bin2hex(random_bytes(16)),
            'is_active' => true,
            'is_global_admin' => false,
        ], $attributes));
    }

    protected function createProject(?string $name = null): Project
    {
        return Project::query()->create([
            'name' => $name ?? 'Projekt '.bin2hex(random_bytes(4)),
        ]);
    }

    protected function addMembership(
        User $user,
        Project $project,
        ProjectRole $role = ProjectRole::VIEWER,
    ): ProjectMembership {
        return ProjectMembership::query()->create([
            'user_id' => $user->getKey(),
            'project_id' => $project->getKey(),
            'role' => $role,
        ]);
    }
}
