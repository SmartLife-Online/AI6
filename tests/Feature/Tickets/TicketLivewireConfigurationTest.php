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

        config(['livewire.inject_assets' => false, 'livewire.navigate.show_progress_bar' => true]);

        try {
            AI6ServiceProvider::assertLivewireContentSecurityConfiguration();
            self::fail('Enabling the Livewire progress bar must fail closed.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('livewire.navigate.show_progress_bar', $exception->getMessage());
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
        $guardTag = '<script src="http://localhost/assets/ai6-livewire-progress-guard.js"></script>';
        $page->assertSee($guardTag, false);
        $page->assertDontSee('<style', false);
        $page->assertSee('<link rel="stylesheet" href="http://localhost/assets/ai6.css">', false);

        $html = $page->getContent();
        self::assertIsString($html);
        $guardPosition = strpos($html, $guardTag);
        $livewirePosition = strpos($html, 'src="http://localhost/assets/livewire/livewire.js?id='.$versionHash.'"');
        self::assertIsInt($guardPosition);
        self::assertIsInt($livewirePosition);
        self::assertLessThan($livewirePosition, $guardPosition, 'The progress-style guard must execute before Livewire.');

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

    public function test_the_disabled_progress_style_is_rejected_only_for_the_known_vendor_hash(): void
    {
        $guardPath = public_path('assets/ai6-livewire-progress-guard.js');
        self::assertFileExists($guardPath);
        $guard = file_get_contents($guardPath);
        self::assertIsString($guard);
        self::assertStringContainsString(
            "expectedStyleSha256 = 'wHM+htXdtkideW9K/pE8sHwN7LYOKJTCZfrrEvY5Qvg='",
            $guard,
        );
        self::assertStringContainsString("subtle.digest('SHA-256'", $guard);
        self::assertStringContainsString('actual !== expectedStyleSha256', $guard);
        self::assertStringContainsString('css !== expectedStyleCss', $guard);
        self::assertStringContainsString('appendUnlessConnected(node)', $guard);
        self::assertStringNotContainsString('unsafe-inline', $guard);
        self::assertStringNotContainsString('console.', $guard);

        $styles = [];

        foreach (['livewire.csp.js', 'livewire.csp.min.js'] as $bundleName) {
            $style = $this->livewireProgressStyle($bundleName);
            self::assertSame(
                'wHM+htXdtkideW9K/pE8sHwN7LYOKJTCZfrrEvY5Qvg=',
                base64_encode(hash('sha256', $style, true)),
                sprintf('A changed vendor style in %s must fail this binding.', $bundleName),
            );
            $styles[] = $style;
        }

        self::assertSame($styles[0], $styles[1]);
        self::assertMatchesRegularExpression("/expectedStyleCss = atob\\('([^']+)'\\)/", $guard);
        preg_match("/expectedStyleCss = atob\\('([^']+)'\\)/", $guard, $matches);
        $fallbackStyle = base64_decode($matches[1], true);
        self::assertIsString($fallbackStyle);
        self::assertSame(
            $styles[0],
            $fallbackStyle,
            'The non-WebCrypto fallback must stay byte-bound to the same known vendor style.',
        );
    }

    private function livewireProgressStyle(string $bundleName): string
    {
        $bundle = file_get_contents(base_path('vendor/livewire/livewire/dist/'.$bundleName));
        self::assertIsString($bundle);
        $marker = '/* Make clicks pass-through */';
        $markerPosition = strpos($bundle, $marker);
        self::assertIsInt($markerPosition);
        $styleStart = strrpos(substr($bundle, 0, $markerPosition), '`');
        self::assertIsInt($styleStart);
        $styleEnd = strpos($bundle, '`', $markerPosition);
        self::assertIsInt($styleEnd);

        return substr($bundle, $styleStart + 1, $styleEnd - $styleStart - 1);
    }
}
