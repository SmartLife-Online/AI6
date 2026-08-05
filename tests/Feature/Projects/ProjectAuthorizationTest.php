<?php

namespace Tests\Feature\Projects;

use App\AI6\Projects\ProjectRole;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Auth\AuthFeatureTestCase;
use Tests\Unit\Auth\ExpectedAuthorizationMatrix;

final class ProjectAuthorizationTest extends AuthFeatureTestCase
{
    public function test_each_project_role_can_list_and_view_only_its_own_projects(): void
    {
        $expected = ExpectedAuthorizationMatrix::projectActions();

        foreach (ProjectRole::cases() as $role) {
            $user = $this->createUser();
            $visibleProject = $this->createProject('Sichtbar '.$role->value);
            $hiddenProject = $this->createProject('Verborgen '.$role->value);
            $this->addMembership($user, $visibleProject, $role);

            self::assertTrue($expected['appear_in_list'][$role->value]);
            self::assertTrue($expected['view_details'][$role->value]);

            $this->actingAs($user)
                ->get(route('projects.index'))
                ->assertOk()
                ->assertSee($visibleProject->name)
                ->assertDontSee($hiddenProject->name);
            $this->actingAs($user)
                ->get(route('projects.show', $visibleProject))
                ->assertOk()
                ->assertSee($visibleProject->name);
            $this->actingAs($user)
                ->get(route('projects.show', $hiddenProject))
                ->assertForbidden()
                ->assertDontSee($hiddenProject->name);
        }
    }

    public function test_user_and_global_administrator_without_membership_receive_no_project_content(): void
    {
        $project = $this->createProject('Nicht offenlegen '.bin2hex(random_bytes(4)));

        foreach ([
            $this->createUser(),
            $this->createUser(['is_global_admin' => true]),
        ] as $user) {
            $this->actingAs($user)
                ->get(route('projects.index'))
                ->assertOk()
                ->assertDontSee($project->name);
            $this->actingAs($user)
                ->get(route('projects.show', $project))
                ->assertForbidden()
                ->assertDontSee($project->name);
        }
    }

    public function test_every_registered_project_bound_route_denies_a_non_member(): void
    {
        $project = $this->createProject('Gebunden '.bin2hex(random_bytes(4)));
        $user = $this->createUser();
        $projectRoutes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with((string) $route->getName(), 'projects.')
                && str_contains($route->uri(), '{project}'),
        ));

        self::assertNotSame([], $projectRoutes);

        foreach ($projectRoutes as $route) {
            self::assertSame(['GET', 'HEAD'], $route->methods());
            $this->actingAs($user)
                ->get('/'.str_replace('{project}', (string) $project->getKey(), $route->uri()))
                ->assertForbidden()
                ->assertDontSee($project->name);
        }
    }

    public function test_project_creation_page_and_every_mutating_project_route_are_policy_bound(): void
    {
        $projectRoutes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with((string) $route->getName(), 'projects.'),
        ));

        $creationPage = array_values(array_filter(
            $projectRoutes,
            static fn ($route): bool => $route->getName() === 'projects.create',
        ));
        self::assertCount(1, $creationPage);
        self::assertContains('can:create,App\AI6\Projects\Models\Project', $creationPage[0]->gatherMiddleware());

        $mutationRoutes = array_values(array_filter(
            $projectRoutes,
            static fn ($route): bool => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
        ));

        self::assertNotSame([], $mutationRoutes);
        foreach ($mutationRoutes as $route) {
            $authorizationMiddleware = array_filter(
                $route->gatherMiddleware(),
                static fn (string $middleware): bool => str_starts_with($middleware, 'can:'),
            );
            self::assertNotSame([], $authorizationMiddleware, (string) $route->getName());
        }
    }
}
