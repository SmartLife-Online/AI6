<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Actions\DeactivateUser;
use App\AI6\Auth\Actions\DeleteUser;
use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Base64Url;
use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\EnrollmentSessionManager;
use App\AI6\Auth\LbuchsPasskeyCeremony;
use App\AI6\Auth\Models\AuthenticationAuditEntry;
use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\RecoveryCode;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\Models\UserSession;
use App\AI6\Auth\PasskeyCeremony;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyManager;
use App\AI6\Auth\PasskeyOptions;
use App\AI6\Auth\PasskeyRegistration;
use App\AI6\Auth\PasskeyRelyingParty;
use App\AI6\Auth\TotpManager;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Tests\Unit\Auth\RealWebAuthnFixture;

final class PasskeyEnrollmentTest extends AuthFeatureTestCase
{
    public function test_password_bound_enrollment_only_reaches_factor_registration_and_then_requires_regular_login(): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        $this->configureConfirmation();
        $this->app->instance(PasskeyCeremony::class, new DeterministicPasskeyCeremony);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'enrollment@example.test',
            'password' => 'correct-password',
            'is_global_admin' => true,
        ]);
        RecoveryCode::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make('RECOVERY-ONLY'),
            'issued_at' => now(),
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('auth.enrollment.totp.show'))
            ->assertSessionHas(AuthenticationSession::STATE_KEY, AuthenticationSession::STATE_ENROLLMENT);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();

        $this->get(route('projects.index'))->assertRedirect(route('auth.enrollment.totp.show'));
        $this->postJson(route('admin.users.create'), [
            'name' => 'Nicht erlaubt',
            'email' => 'blocked@example.test',
            'password' => 'irrelevant-password',
        ])->assertForbidden();
        $this->get(route('auth.enrollment.totp.show'))->assertOk();
        $this->get(route('auth.enrollment.passkey.show'))->assertOk();
        self::assertNotSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));
        self::assertNotSame(0, Artisan::call('ai6:reissue-recovery-codes', ['email' => $user->email]));

        $this->postJson(route('auth.enrollment.passkey.options'))
            ->assertOk()
            ->assertJsonPath('publicKey.rp.id', 'localhost');
        $this->postJson(route('auth.enrollment.passkey.register'), [
            'credential_id' => 'credential-fixture',
            'client_data_json' => 'exact-origin',
            'attestation_object' => 'valid-attestation',
            'label' => 'Arbeitsplatz',
        ])->assertOk()->assertJsonPath('redirect', route('login'));

        $this->assertGuest();
        self::assertDatabaseHas('passkey_credentials', [
            'user_id' => $user->getKey(),
            'credential_id' => 'credential-fixture',
            'signature_counter' => 1,
            'label' => 'Arbeitsplatz',
        ]);
        self::assertSame(
            ['enrollment_started', 'passkey_registered', 'enrollment_completed'],
            AuthenticationAuditEntry::query()->orderBy('id')->pluck('event')->all(),
        );
        $auditDump = json_encode(AuthenticationAuditEntry::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('credential-fixture', $auditDump);
        self::assertStringNotContainsString('valid-attestation', $auditDump);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->postJson(route('auth.primary.passkey.options'))->assertOk();
        $this->postJson(route('auth.primary.passkey.verify'), [
            'credential_id' => 'credential-fixture',
            'client_data_json' => 'exact-origin',
            'authenticator_data' => 'valid-authenticator',
            'signature' => 'valid-signature',
            'user_handle' => null,
        ])->assertOk()->assertJsonPath('redirect', route('auth.confirmation.show'));
        self::assertSame(
            AuthenticationSession::STATE_EMAIL_PENDING,
            session(AuthenticationSession::STATE_KEY),
        );
        Mail::assertSentCount(1);
    }

    public function test_passkey_challenge_origin_and_counter_rejections_consume_no_authorization_state(): void
    {
        $this->app->instance(PasskeyCeremony::class, new DeterministicPasskeyCeremony);
        $user = $this->createUser();
        $credential = $this->createPasskey($user);
        $manager = $this->app->make(PasskeyManager::class);
        $request = $this->sessionRequest('passkey-negative');

        $manager->authenticationOptions($request, $user);
        $challengeState = $request->session()->get('ai6.auth.webauthn');
        self::assertIsArray($challengeState);
        $challengeState['challenge'] = 'foreign-challenge';
        $request->session()->put('ai6.auth.webauthn', $challengeState);
        $this->assertPasskeyRejected($manager, $request, $user, 'valid-signature');

        $manager->authenticationOptions($request, $user);
        $this->assertPasskeyRejected($manager, $request, $user, 'wrong-origin');

        $manager->authenticationOptions($request, $user);
        $this->assertPasskeyRejected($manager, $request, $user, 'rollback-counter');

        self::assertSame(1, $credential->fresh()->signature_counter);
        self::assertNull($request->session()->get(AuthenticationSession::STATE_KEY));

        $manager->authenticationOptions($request, $user);
        $manager->authenticate($request, $user, $this->authenticationFixture('valid-signature'));
        self::assertSame(2, $credential->fresh()->signature_counter);
    }

    public function test_resolved_lbuchs_ceremony_rejects_real_challenge_origin_and_counter_failures(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        Log::swap($logger);
        $ceremony = new LbuchsPasskeyCeremony(
            new PasskeyRelyingParty('AI6 Test', 'localhost', 'http://localhost:8000'),
        );
        $user = $this->createUser();
        $registrationChallenge = 'resolved-library-registration';
        $registration = $ceremony->verifyRegistration(
            RealWebAuthnFixture::registrationResponse($registrationChallenge, 'http://localhost:8000'),
            Base64Url::encode($registrationChallenge),
        );
        $credential = PasskeyCredential::query()->create([
            'user_id' => $user->getKey(),
            'credential_id' => $registration->credentialId,
            'credential_public_key' => $registration->publicKey,
            'signature_counter' => $registration->signatureCounter,
            'label' => 'Real library fixture',
        ]);
        $authenticationChallenge = 'resolved-library-authentication';
        $validResponse = RealWebAuthnFixture::authenticationResponse(
            $authenticationChallenge,
            'http://localhost:8000',
            RealWebAuthnFixture::REGISTRATION_COUNTER + 1,
        );

        self::assertSame(
            RealWebAuthnFixture::REGISTRATION_COUNTER + 1,
            $ceremony->verifyAuthentication(
                $credential,
                $user,
                $validResponse,
                Base64Url::encode($authenticationChallenge),
            ),
        );

        $this->assertRealCeremonyRejected(
            $ceremony,
            $credential,
            $user,
            RealWebAuthnFixture::authenticationResponse(
                'foreign-challenge',
                'http://localhost:8000',
                RealWebAuthnFixture::REGISTRATION_COUNTER + 2,
            ),
            $authenticationChallenge,
            $logger,
            'invalid_challenge',
        );
        $this->assertRealCeremonyRejected(
            $ceremony,
            $credential,
            $user,
            RealWebAuthnFixture::authenticationResponse(
                $authenticationChallenge,
                'http://different.localhost:8000',
                RealWebAuthnFixture::REGISTRATION_COUNTER + 2,
            ),
            $authenticationChallenge,
            $logger,
            'invalid_response',
        );

        $credential->forceFill(['signature_counter' => RealWebAuthnFixture::REGISTRATION_COUNTER + 1]);
        $this->assertRealCeremonyRejected(
            $ceremony,
            $credential,
            $user,
            RealWebAuthnFixture::authenticationResponse(
                $authenticationChallenge,
                'http://localhost:8000',
                RealWebAuthnFixture::REGISTRATION_COUNTER + 1,
            ),
            $authenticationChallenge,
            $logger,
            'invalid_signature_counter',
        );
    }

    public function test_passkey_challenge_has_a_fixed_window_independent_of_mail_confirmation(): void
    {
        config(['ai6.auth.login_confirmation_ttl_seconds' => '86400']);
        $this->app->forgetInstance(AuthConfiguration::class);
        $this->app->instance(PasskeyCeremony::class, new DeterministicPasskeyCeremony);
        $user = $this->createUser();
        $this->createPasskey($user);
        $manager = $this->app->make(PasskeyManager::class);
        $request = $this->sessionRequest('fixed-passkey-window');

        $manager->authenticationOptions($request, $user);
        $this->travel(PasskeyCeremony::TIMEOUT_SECONDS + 1)->seconds();

        $this->assertPasskeyRejected($manager, $request, $user, 'valid-signature');
    }

    public function test_passkey_option_rejections_are_safe_on_primary_enrollment_and_step_up_routes(): void
    {
        $primaryUser = $this->createUser([
            'email' => 'no-passkey@example.test',
            'password' => 'correct-password',
        ]);
        $this->createConfirmedTotp($primaryUser);

        $this->post(route('login.store'), [
            'email' => $primaryUser->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->postJson(route('auth.primary.passkey.options'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passkey')
            ->assertJsonMissing(['message' => 'The WebAuthn ceremony was rejected.']);
        $this->post(route('logout'));

        $this->app->instance(PasskeyCeremony::class, new RejectingPasskeyOptionsCeremony);
        $enrollmentUser = $this->createUser([
            'email' => 'enrollment-options@example.test',
            'password' => 'correct-password',
        ]);
        $this->post(route('login.store'), [
            'email' => $enrollmentUser->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.enrollment.totp.show'));
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $this->postJson(route('auth.enrollment.passkey.options'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passkey')
            ->assertJsonMissing(['message' => 'The WebAuthn ceremony was rejected.']);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->actingAs($primaryUser);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $this->postJson(route('auth.step-up.passkey.options', ['action' => 'control_branch.change']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('passkey')
            ->assertJsonMissing(['message' => 'The WebAuthn ceremony was rejected.']);
    }

    public function test_totp_enrollment_encrypts_the_secret_and_rejects_same_window_replay(): void
    {
        $this->configureConfirmation();
        Mail::fake();
        $user = $this->createUser([
            'email' => 'totp-enrollment@example.test',
            'password' => 'correct-password',
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password']);
        $this->preserveCurrentSessionCookie();
        $response = $this->get(route('auth.enrollment.totp.show'))->assertOk();
        self::assertSame(1, preg_match('/\b([A-Z2-7]{16,})\b/', $response->getContent(), $matches));
        $secret = $matches[1];
        $code = $this->currentTotpCode($secret);

        $this->post(route('auth.enrollment.totp.confirm'), ['code' => $code])
            ->assertRedirect(route('login'));
        $credential = $user->totpCredential()->sole();
        self::assertNotSame($secret, $credential->encrypted_secret);
        self::assertStringNotContainsString($secret, $credential->encrypted_secret);
        self::assertNotNull($credential->confirmed_at);
        self::assertFalse($this->app->make(TotpManager::class)->verify($user, $code));

        $google2fa = $this->app->make(Google2FA::class);
        $oathTotp = new ReflectionMethod($google2fa, 'oathTotp');
        $freshCode = $oathTotp->invoke($google2fa, $secret, $google2fa->getTimestamp() + 1);
        self::assertIsString($freshCode);
        self::assertTrue($this->app->make(TotpManager::class)->verify($user, $freshCode));
        self::assertFalse($this->app->make(TotpManager::class)->verify($user, $freshCode));
        self::assertFalse($this->app->make(TotpManager::class)->verify($user, $code));
    }

    public function test_enrollment_expires_and_is_revoked_by_password_change_foreign_session_and_logout(): void
    {
        $this->configureConfirmation(enrollmentTtl: 2);
        $expired = $this->createUser([
            'email' => 'expired-enrollment@example.test',
            'password' => 'correct-password',
        ]);
        $this->post(route('login.store'), ['email' => $expired->email, 'password' => 'correct-password']);
        $this->preserveCurrentSessionCookie();
        $this->travel(3)->seconds();
        $this->get(route('auth.enrollment.totp.show'))->assertRedirect(route('login'));
        $this->assertGuest();
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $expired->getKey(),
            'event' => 'enrollment_expired',
        ]);

        $passwordChanged = $this->createUser([
            'email' => 'changed-password@example.test',
            'password' => 'old-password',
        ]);
        $this->post(route('login.store'), ['email' => $passwordChanged->email, 'password' => 'old-password']);
        $this->preserveCurrentSessionCookie();
        $passwordChanged->update(['password' => 'new-password']);
        Auth::forgetGuards();
        $this->get(route('auth.enrollment.totp.show'))->assertRedirect(route('login'));
        $this->assertGuest();
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $passwordChanged->getKey(),
            'event' => 'enrollment_revoked',
        ]);

        $foreign = $this->createUser();
        $requestA = $this->sessionRequest('enrollment-browser-a');
        $requestB = $this->sessionRequest('enrollment-browser-b');
        $enrollment = $this->app->make(EnrollmentSessionManager::class);
        $enrollment->start($requestA, $foreign);
        $requestB->session()->put('ai6.auth.state', AuthenticationSession::STATE_ENROLLMENT);
        $requestB->session()->put('ai6.auth.enrollment', $requestA->session()->get('ai6.auth.enrollment'));
        self::assertFalse($enrollment->isValid($requestB, $foreign));
        self::assertTrue($enrollment->isValid($requestA, $foreign));

        $logout = $this->createUser([
            'email' => 'logout-enrollment@example.test',
            'password' => 'correct-password',
        ]);
        $this->post(route('login.store'), ['email' => $logout->email, 'password' => 'correct-password']);
        $this->preserveCurrentSessionCookie();
        $this->post(route('logout'))->assertRedirect(route('login'));
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $logout->getKey(),
            'event' => 'enrollment_revoked',
        ]);
    }

    public function test_deactivation_and_deletion_audit_existing_enrollment_session_revocation(): void
    {
        $deactivated = $this->createUser();
        $this->insertEnrollmentSession($deactivated, 'deactivate-enrollment');
        $this->app->make(DeactivateUser::class)->handle($deactivated);
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $deactivated->getKey(),
            'event' => 'enrollment_revoked',
        ]);

        $deleted = $this->createUser();
        $deletedId = $deleted->getKey();
        $this->insertEnrollmentSession($deleted, 'delete-enrollment');
        $this->app->make(DeleteUser::class)->handle($deleted);
        self::assertDatabaseMissing('users', ['id' => $deletedId]);
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $deletedId,
            'event' => 'enrollment_revoked',
        ]);
    }

    private function configureConfirmation(int $enrollmentTtl = 900): void
    {
        config([
            'ai6.auth.login_confirmation_email' => 'security@example.test',
            'ai6.auth.enrollment_ttl_seconds' => (string) $enrollmentTtl,
        ]);
        $this->app->forgetInstance(AuthConfiguration::class);
    }

    private function assertPasskeyRejected(
        PasskeyManager $manager,
        Request $request,
        User $user,
        string $fixture,
    ): void {
        try {
            $manager->authenticate($request, $user, $this->authenticationFixture($fixture));
            self::fail('The passkey fixture must be rejected.');
        } catch (PasskeyCeremonyRejectedException) {
            self::assertNull($request->session()->get(AuthenticationSession::STATE_KEY));
        }
    }

    /**
     * @param  array<string, string|null>  $response
     * @param  object{records: list<array{level: string, message: string, context: array<string, mixed>}>}  $logger
     */
    private function assertRealCeremonyRejected(
        LbuchsPasskeyCeremony $ceremony,
        PasskeyCredential $credential,
        User $user,
        array $response,
        string $challenge,
        object $logger,
        string $expectedReason,
    ): void {
        try {
            $ceremony->verifyAuthentication(
                $credential,
                $user,
                $response,
                Base64Url::encode($challenge),
            );
            self::fail('The real WebAuthn fixture must be rejected.');
        } catch (PasskeyCeremonyRejectedException) {
        }

        $record = end($logger->records);
        self::assertIsArray($record);
        self::assertSame('Passkey ceremony rejected.', $record['message']);
        self::assertSame('authentication', $record['context']['operation'] ?? null);
        self::assertSame($expectedReason, $record['context']['reason'] ?? null);
    }

    /** @return array<string, string|null> */
    private function authenticationFixture(string $fixture): array
    {
        return [
            'credential_id' => 'credential-fixture',
            'client_data_json' => $fixture === 'wrong-origin' ? 'wrong-origin' : 'exact-origin',
            'authenticator_data' => 'valid-authenticator',
            'signature' => $fixture,
            'user_handle' => null,
        ];
    }

    private function sessionRequest(string $id): Request
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId($id);
        $session->start();
        $request = Request::create('/auth/passkey', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }

    private function insertEnrollmentSession(User $user, string $id): void
    {
        UserSession::query()->create([
            'id' => $id,
            'user_id' => $user->getKey(),
            'payload' => base64_encode(serialize([
                AuthenticationSession::STATE_KEY => AuthenticationSession::STATE_ENROLLMENT,
            ])),
            'last_activity' => time(),
        ]);
    }
}

final class DeterministicPasskeyCeremony implements PasskeyCeremony
{
    public function registrationOptions(User $user, array $excludedCredentialIds): PasskeyOptions
    {
        return new PasskeyOptions([
            'challenge' => Base64Url::encode('registration-challenge'),
            'rp' => ['id' => 'localhost', 'name' => 'AI6'],
            'user' => [
                'id' => Base64Url::encode((string) $user->getKey()),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'excludeCredentials' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'public-key'],
                $excludedCredentialIds,
            ),
        ], 'registration-challenge');
    }

    public function authenticationOptions(array $allowedCredentialIds): PasskeyOptions
    {
        return new PasskeyOptions([
            'challenge' => Base64Url::encode('authentication-challenge'),
            'rpId' => 'localhost',
            'allowCredentials' => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'public-key'],
                $allowedCredentialIds,
            ),
        ], 'authentication-challenge');
    }

    public function verifyRegistration(array $response, string $challenge): PasskeyRegistration
    {
        if ($challenge !== 'registration-challenge'
            || ($response['client_data_json'] ?? null) !== 'exact-origin'
            || ($response['attestation_object'] ?? null) !== 'valid-attestation') {
            throw new PasskeyCeremonyRejectedException;
        }

        return new PasskeyRegistration('credential-fixture', 'public-key-fixture', 1);
    }

    public function verifyAuthentication(
        PasskeyCredential $credential,
        User $user,
        array $response,
        string $challenge,
    ): int {
        if ($challenge !== 'authentication-challenge'
            || ($response['client_data_json'] ?? null) !== 'exact-origin'
            || ($response['signature'] ?? null) !== 'valid-signature'
            || $credential->user_id !== $user->getKey()) {
            throw new PasskeyCeremonyRejectedException;
        }

        return $credential->signature_counter + 1;
    }
}

final class RejectingPasskeyOptionsCeremony implements PasskeyCeremony
{
    public function registrationOptions(User $user, array $excludedCredentialIds): PasskeyOptions
    {
        throw new PasskeyCeremonyRejectedException;
    }

    public function authenticationOptions(array $allowedCredentialIds): PasskeyOptions
    {
        throw new PasskeyCeremonyRejectedException;
    }

    public function verifyRegistration(array $response, string $challenge): PasskeyRegistration
    {
        throw new PasskeyCeremonyRejectedException;
    }

    public function verifyAuthentication(
        PasskeyCredential $credential,
        User $user,
        array $response,
        string $challenge,
    ): int {
        throw new PasskeyCeremonyRejectedException;
    }
}
