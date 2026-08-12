<?php

namespace Tests\Feature\Tickets;

use App\AI6\Shared\AI6ServiceProvider;
use App\AI6\Shared\Config\ConfigurationException;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\Auth\AuthFeatureTestCase;

final class TicketLivewireConfigurationTest extends AuthFeatureTestCase
{
    public function test_the_csp_safe_mode_is_mandatory_and_inline_injection_stays_disabled(): void
    {
        self::assertTrue(config('livewire.csp_safe'));
        self::assertFalse(config('livewire.inject_assets'));
        self::assertFalse(config('livewire.navigate.show_progress_bar'));

        config(['livewire.csp_safe' => false]);

        try {
            AI6ServiceProvider::assertLivewireContentSecurityConfiguration();
            self::fail('Disabling the CSP-safe bundle must fail closed.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('livewire.csp_safe', $exception->getMessage());
        }

        config(['livewire.csp_safe' => true, 'livewire.inject_assets' => true]);

        try {
            AI6ServiceProvider::assertLivewireContentSecurityConfiguration();
            self::fail('Enabling automatic asset injection must fail closed.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('livewire.inject_assets', $exception->getMessage());
        }
    }

    public function test_the_script_route_stays_hash_bound_under_the_assets_path_and_serves_the_csp_bundle(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('vendor/livewire/livewire/dist/manifest.json')),
            true,
        );
        self::assertIsArray($manifest);
        $versionHash = $manifest['/livewire.js'] ?? null;
        self::assertIsString($versionHash);

        $login = $this->get('/login');
        $login->assertOk();
        $login->assertDontSee('<style', false);
        $login->assertDontSee('/assets/livewire/livewire.js', false);
        $login->assertSee('<link rel="stylesheet" href="http://localhost/assets/ai6.css">', false);

        $user = $this->createUser();
        $project = $this->createProject();
        $this->addMembership($user, $project);
        $page = $this->actingAs($user)->get('/projects/'.$project->getKey().'/tickets');
        $page->assertOk();
        $page->assertSee(
            'src="http://localhost/assets/livewire/livewire.js?id='.$versionHash.'"',
            false,
        );
        $page->assertDontSee('<style', false);
        $page->assertSee('<link rel="stylesheet" href="http://localhost/assets/ai6.css">', false);

        $expectedBundle = config('app.debug') ? 'livewire.csp.js' : 'livewire.csp.min.js';
        $expectedBytes = file_get_contents(base_path('vendor/livewire/livewire/dist/'.$expectedBundle));
        self::assertIsString($expectedBytes);

        $script = $this->get('/assets/livewire/livewire.js');
        $script->assertOk();
        $served = $script->streamedContent();
        self::assertSame(
            hash('sha256', $expectedBytes),
            hash('sha256', $served),
            'The script route must serve exactly the CSP-safe Livewire bundle.',
        );

        $regularBundle = file_get_contents(base_path('vendor/livewire/livewire/dist/'
            .(config('app.debug') ? 'livewire.js' : 'livewire.min.js')));
        self::assertIsString($regularBundle);
        self::assertNotSame(
            hash('sha256', $regularBundle),
            hash('sha256', $served),
            'The eval-dependent regular bundle must never be served.',
        );
    }

    public function test_the_unused_livewire_package_endpoints_answer_not_found(): void
    {
        $this->get(EndpointResolver::scriptPath(minified: false))->assertNotFound();
        $this->get(EndpointResolver::scriptPath(minified: true))->assertNotFound();
        $this->get(EndpointResolver::mapPath(csp: false))->assertNotFound();
        $this->get(EndpointResolver::mapPath(csp: true))->assertNotFound();
        $this->get(EndpointResolver::prefix().'/js/some-component.js')->assertNotFound();
        $this->get(EndpointResolver::prefix().'/css/some-component.css')->assertNotFound();
        $this->get(EndpointResolver::prefix().'/css/some-component.global.css')->assertNotFound();
        $this->get(EndpointResolver::prefix().'/preview-file/some-file.png')->assertNotFound();
        $this->post(EndpointResolver::uploadPath())->assertNotFound();
    }

    public function test_the_responsive_stylesheet_is_a_same_origin_external_asset(): void
    {
        self::assertFileExists(public_path('assets/ai6.css'));
        $stylesheet = file_get_contents(public_path('assets/ai6.css'));
        self::assertIsString($stylesheet);
        self::assertStringContainsString('@media (max-width: 480px)', $stylesheet);
        self::assertStringNotContainsString('@import', $stylesheet);
        self::assertStringNotContainsString('url(http', $stylesheet);
    }
}
