<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Actions\DeactivateUser;
use App\AI6\Auth\Actions\DeleteUser;
use App\AI6\Auth\Models\UserSession;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

final class SessionRevocationTest extends AuthFeatureTestCase
{
    public function test_deactivation_removes_all_sessions_and_logs_out_the_client_on_its_next_request(): void
    {
        $password = bin2hex(random_bytes(16));
        $user = $this->createUser([
            'email' => 'deactivate-session@example.test',
            'password' => $password,
        ]);
        $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect(route('projects.index'));
        $this->insertSessionRows($user->getKey(), 2);

        $this->app->make(DeactivateUser::class)->handle($user);
        Auth::forgetGuards();

        self::assertDatabaseCount('sessions', 0);
        $this->get(route('projects.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_deletion_removes_all_sessions_and_logs_out_the_client_on_its_next_request(): void
    {
        $password = bin2hex(random_bytes(16));
        $user = $this->createUser([
            'email' => 'delete-session@example.test',
            'password' => $password,
        ]);
        $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect(route('projects.index'));
        $this->insertSessionRows($user->getKey(), 2);

        $this->app->make(DeleteUser::class)->handle($user);
        Auth::forgetGuards();

        self::assertDatabaseCount('sessions', 0);
        self::assertDatabaseMissing('users', ['id' => $user->getKey()]);
        $this->get(route('projects.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_global_administrator_revokes_exactly_one_selected_session(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $user = $this->createUser();
        $sessions = $this->insertSessionRows($user->getKey(), 2);

        $this->actingAs($administrator)
            ->deleteJson(route('admin.users.sessions.revoke', [$user, $sessions[0]]))
            ->assertNoContent();

        self::assertDatabaseMissing('sessions', ['id' => $sessions[0]]);
        self::assertDatabaseHas('sessions', [
            'id' => $sessions[1],
            'user_id' => $user->getKey(),
        ]);

        $sessionCookie = config('session.cookie');
        self::assertIsString($sessionCookie);

        Auth::forgetGuards();
        $this->withCookie($sessionCookie, $sessions[0])
            ->get(route('projects.index'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        Auth::forgetGuards();
        $this->withCookie($sessionCookie, $sessions[1])
            ->get(route('projects.index'))
            ->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /** @return list<string> */
    private function insertSessionRows(int|string $userId, int $count): array
    {
        $ids = [];
        $guard = Auth::guard('web');

        if (! $guard instanceof SessionGuard) {
            self::fail('The web guard must use sessions.');
        }

        $guardSessionKey = $guard->getName();

        for ($index = 0; $index < $count; $index++) {
            $id = bin2hex(random_bytes(20));
            UserSession::query()->create([
                'id' => $id,
                'user_id' => $userId,
                'payload' => base64_encode(serialize([
                    $guardSessionKey => $userId,
                    'index' => $index,
                ])),
                'last_activity' => time(),
            ]);
            $ids[] = $id;
        }

        return $ids;
    }
}
