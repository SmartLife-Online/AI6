<?php

namespace Tests\Feature\Agents;

use Illuminate\Routing\Route;
use Tests\Feature\Auth\AuthFeatureTestCase;

final class AgentProfileViewTest extends AuthFeatureTestCase
{
    public function test_guest_is_redirected_and_completed_user_sees_read_only_profiles(): void
    {
        $this->get('/agents/profiles')->assertRedirect(route('login'));

        $user = $this->createUser();
        $response = $this->actingAs($user)->get('/agents/profiles');
        $response->assertOk()
            ->assertSee('Agentenprofile')
            ->assertSee('Promptkatalog: Version 2')
            ->assertSee('codex-gpt-5.6-terra')
            ->assertSee('grok-cli-review')
            ->assertSee('copilot-cli-review')
            ->assertSee('fake')
            ->assertSee('implementation')
            ->assertSee('quality_review')
            ->assertSee('finding_verification')
            ->assertSee('security_review')
            ->assertSee('unchecked')
            ->assertSee('available')
            ->assertSee('codex-cli-v1')
            ->assertSee('fake-v1');
        $response->assertDontSee('<select', false)
            ->assertDontSee('Profil speichern');
    }

    public function test_agent_profile_surface_has_exactly_one_get_route_and_no_mutation_route(): void
    {
        $routes = array_values(array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn (Route $route): bool => str_starts_with($route->uri(), 'agents/'),
        ));

        self::assertCount(1, $routes);
        self::assertSame('agents.profiles', $routes[0]->getName());
        self::assertSame(['GET', 'HEAD'], $routes[0]->methods());
        self::assertContains('auth', $routes[0]->middleware());

        $controller = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Agents/Http/AgentProfileController.php');
        self::assertIsString($controller);
        foreach (['App\\AI6\\Git', 'App\\AI6\\Runs', 'Process', 'Http::', 'DB::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller);
        }
    }
}
