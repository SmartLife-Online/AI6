<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticationTest extends AuthFeatureTestCase
{
    public function test_public_self_service_routes_do_not_exist(): void
    {
        $selfServiceSegment = 'pass'.'word';
        $confirmationSegment = 'to'.'ken';

        foreach ([
            '/register',
            '/forgot-'.$selfServiceSegment,
            '/reset-'.$selfServiceSegment.'/example-'.$confirmationSegment,
            '/email/verify',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }

        $routeNames = array_values(array_filter(array_map(
            static fn ($route): ?string => $route->getName(),
            Route::getRoutes()->getRoutes(),
        )));
        $joinedNames = implode('|', $routeNames);

        foreach (['register', 'password.reset', 'password.request', 'verification'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $joinedNames);
        }
    }

    public function test_login_normalizes_the_identifier_rate_limits_failures_and_clears_on_success(): void
    {
        $password = bin2hex(random_bytes(16));
        $user = $this->createUser([
            'email' => 'rate.limit@example.test',
            'password' => $password,
        ]);
        config([
            'ai6.auth.login_max_attempts' => '2',
            'ai6.auth.login_decay_seconds' => '1',
            'ai6.auth.session_lifetime_minutes' => '120',
        ]);
        $this->app->forgetInstance(AuthConfiguration::class);

        $this->post('/login', [
            'email' => ' RATE.LIMIT@EXAMPLE.TEST ',
            'password' => bin2hex(random_bytes(16)),
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'rate.limit@example.test',
            'password' => bin2hex(random_bytes(16)),
        ])->assertStatus(429);

        $this->travel(2)->seconds();
        $this->post('/login', [
            'email' => ' Rate.Limit@Example.Test ',
            'password' => $password,
        ])->assertRedirect(route('projects.index'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->post('/login', [
            'email' => 'rate.limit@example.test',
            'password' => bin2hex(random_bytes(16)),
        ])->assertSessionHasErrors('email');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $password = bin2hex(random_bytes(16));
        $this->createUser([
            'email' => 'inactive@example.test',
            'password' => $password,
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => 'inactive@example.test',
            'password' => $password,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_regenerates_the_session_and_logout_invalidates_session_and_csrf_token(): void
    {
        $password = bin2hex(random_bytes(16));
        $this->createUser([
            'email' => 'session@example.test',
            'password' => $password,
        ]);
        $this->withSession(['probe' => 'before-login']);
        $sessionStore = $this->app->make('session')->driver();
        $sessionIdBeforeLogin = $sessionStore->getId();

        $this->post('/login', [
            'email' => 'session@example.test',
            'password' => $password,
        ])->assertRedirect(route('projects.index'));

        self::assertNotSame($sessionIdBeforeLogin, $sessionStore->getId());
        $csrfTokenBeforeLogout = $sessionStore->token();
        $sessionIdBeforeLogout = $sessionStore->getId();

        $this->post('/logout')->assertRedirect(route('login'));

        self::assertNotSame($sessionIdBeforeLogout, $sessionStore->getId());
        self::assertNotSame($csrfTokenBeforeLogout, $sessionStore->token());
        $this->assertGuest();

        $request = Request::create(
            '/logout',
            'POST',
            ['_token' => $csrfTokenBeforeLogout],
        );
        $request->setLaravelSession($sessionStore);
        $middleware = new class($this->app, $this->app->make('encrypter')) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, static fn (): Response => response('unexpected'));
    }
}
