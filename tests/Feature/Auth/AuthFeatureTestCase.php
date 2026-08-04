<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\TotpCredential;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\TotpSecretCipher;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

abstract class AuthFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'mail.default' => 'smtp',
            'session.driver' => 'database',
        ]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
    }

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        parent::actingAs($user, $guard);

        return $this->withSession([
            AuthenticationSession::STATE_KEY => AuthenticationSession::STATE_AUTHORIZED,
            'ai6.auth.authorized_at' => now()->getTimestamp(),
        ]);
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

    protected function createConfirmedTotp(User $user, ?string $secret = null): string
    {
        $google2fa = $this->app->make(Google2FA::class);
        $secret ??= $google2fa->generateSecretKey();

        TotpCredential::query()->create([
            'user_id' => $user->getKey(),
            'encrypted_secret' => $this->app->make(TotpSecretCipher::class)->encrypt($secret),
            'confirmed_at' => now(),
        ]);

        return $secret;
    }

    protected function currentTotpCode(string $secret): string
    {
        return $this->app->make(Google2FA::class)->getCurrentOtp($secret);
    }

    protected function createPasskey(User $user, string $credentialId = 'credential-fixture'): PasskeyCredential
    {
        return PasskeyCredential::query()->create([
            'user_id' => $user->getKey(),
            'credential_id' => $credentialId,
            'credential_public_key' => 'public-key-fixture',
            'signature_counter' => 1,
            'label' => 'Testpasskey',
        ]);
    }

    protected function preserveCurrentSessionCookie(): void
    {
        $cookieName = config('session.cookie');
        self::assertIsString($cookieName);
        $this->withCookie($cookieName, $this->app->make('session')->driver()->getId());
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
