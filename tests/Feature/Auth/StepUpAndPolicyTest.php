<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Models\AuthenticationAuditEntry;
use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\RecoveryCode;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\PasskeyCeremony;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyOptions;
use App\AI6\Auth\PasskeyRegistration;
use App\AI6\Auth\Policies\PrimaryAuthenticationPolicy;
use App\AI6\Auth\PrimaryAuthenticationMethod;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Auth\StepUpRequiredException;
use App\AI6\Auth\StrongAuthenticationAttemptLimiter;
use App\AI6\Projects\ProjectRole;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Doctor\SecurityPolicyDoctorCheck;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use ReflectionMethod;

final class StepUpAndPolicyTest extends AuthFeatureTestCase
{
    public function test_step_up_proof_is_fresh_single_use_and_bound_to_user_session_and_action(): void
    {
        config(['ai6.auth.step_up_window_seconds' => '2']);
        $this->app->forgetInstance(AuthConfiguration::class);
        $user = $this->createUser();
        $otherUser = $this->createUser();
        $requestA = $this->sessionRequest('step-up-browser-a');
        $requestB = $this->sessionRequest('step-up-browser-b');
        $guard = $this->app->make(StepUpGuard::class);

        $this->assertStepUpRejected($guard, $requestA, $user, 'control_branch.change');
        $guard->markSatisfied($requestA, $user, 'control_branch.change');

        $requestB->session()->put('ai6.auth.step_up', $requestA->session()->get('ai6.auth.step_up'));
        $this->assertStepUpRejected($guard, $requestB, $user, 'control_branch.change');
        $this->assertStepUpRejected($guard, $requestA, $otherUser, 'control_branch.change');
        $this->assertStepUpRejected($guard, $requestA, $user, 'recovery.resolve');

        $guard->consumeFresh($requestA, $user, 'control_branch.change');
        $this->assertStepUpRejected($guard, $requestA, $user, 'control_branch.change');

        $guard->markSatisfied($requestA, $user, 'control_branch.change');
        $this->travel(3)->seconds();
        $guard->markSatisfied($requestA, $user, 'recovery.resolve');
        $proofs = $requestA->session()->get('ai6.auth.step_up');
        self::assertIsArray($proofs);
        self::assertCount(1, $proofs);
        self::assertArrayHasKey(hash('sha256', 'recovery.resolve'), $proofs);
        $this->travel(3)->seconds();
        $this->assertStepUpRejected($guard, $requestA, $user, 'recovery.resolve');
        self::assertFalse($requestA->session()->has('ai6.auth.step_up'));

        self::assertSame(
            ['step_up_satisfied', 'step_up_satisfied', 'step_up_satisfied'],
            AuthenticationAuditEntry::query()
                ->where('user_id', $user->getKey())
                ->orderBy('id')
                ->pluck('event')
                ->all(),
        );
        $auditDump = json_encode(AuthenticationAuditEntry::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('session_binding', $auditDump);
    }

    public function test_primary_and_step_up_failures_have_session_and_user_bound_attempt_limits(): void
    {
        config([
            'ai6.auth.strong_authentication_max_attempts' => '3',
            'ai6.auth.strong_authentication_decay_seconds' => '2',
        ]);
        $this->app->forgetInstance(AuthConfiguration::class);
        $this->app->instance(SecurityPolicy::class, $this->customPolicy([
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value => false,
        ], true));
        $plainRecoveryCode = 'ABCD-EF12-3456-7890';
        $user = $this->createUser([
            'email' => 'strong-attempts@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $this->createPasskey($user);
        $recoveryCode = RecoveryCode::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($plainRecoveryCode),
            'issued_at' => now(),
        ]);
        $rateLimiter = $this->app->make(RateLimiter::class);
        $primaryRateLimitKey = 'ai6-strong-authentication:primary:'.(int) $user->getKey();
        $stepUpRateLimitKey = 'ai6-strong-authentication:step-up:'.(int) $user->getKey();
        $wrongTotpCode = $this->wrongTotpCode($secret);
        self::assertSame(3, $this->app->make(AuthConfiguration::class)->strongAuthenticationMaxAttempts);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), ['code' => $wrongTotpCode])
            ->assertSessionHasErrors('code');

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.recovery.verify'), ['code' => 'WRONG-RECOVERY'])
            ->assertSessionHasErrors('code');

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.passkey.verify'), [
            'credential_id' => 'credential-fixture',
            'client_data_json' => 'x',
            'authenticator_data' => 'x',
            'signature' => 'x',
            'user_handle' => null,
        ])->assertSessionHasErrors('passkey');
        $this->postJson(route('auth.primary.passkey.options'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('authentication');
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertSessionHasErrors('authentication');
        self::assertSame(3, $rateLimiter->attempts($primaryRateLimitKey));
        self::assertNull($recoveryCode->fresh()->consumed_at);
        self::assertSame(AuthenticationSession::STATE_PRIMARY_PENDING, session(AuthenticationSession::STATE_KEY));

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertSessionHasErrors('authentication');
        $this->travel(3)->seconds();
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertRedirect(route('projects.index'));
        self::assertSame(0, $rateLimiter->attempts($primaryRateLimitKey));

        $actionA = 'control_branch.change';
        $actionB = 'recovery.resolve';
        $actionC = 'project.publish';
        $actionD = 'run.cancel';
        $this->post(route('auth.step-up.totp.verify', ['action' => $actionA]), [
            'code' => $wrongTotpCode,
        ])->assertSessionHasErrors('code');
        self::assertStringContainsString(
            '"attempt_count":1',
            json_encode(session('ai6.auth.strong_attempts'), JSON_THROW_ON_ERROR),
        );
        $this->post(route('auth.step-up.passkey.verify', ['action' => $actionB]), [
            'credential_id' => 'credential-fixture',
            'client_data_json' => 'x',
            'authenticator_data' => 'x',
            'signature' => 'x',
            'user_handle' => null,
        ])->assertSessionHasErrors('passkey');
        $this->post(route('auth.step-up.totp.verify', ['action' => $actionC]), [
            'code' => $wrongTotpCode,
        ])->assertSessionHasErrors('code');
        $this->post(route('auth.step-up.totp.verify', ['action' => $actionD]), [
            'code' => $this->currentTotpCode($secret),
        ])->assertSessionHasErrors('authentication');
        $this->travel(3)->seconds();
        $this->post(route('auth.step-up.totp.verify', ['action' => $actionD]), [
            'code' => $this->nextTotpCode($secret),
        ])->assertSessionHas('status', 'Step-up bestätigt.');
        self::assertSame(0, $rateLimiter->attempts($stepUpRateLimitKey));

        $lockAudits = AuthenticationAuditEntry::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'strong_authentication_locked')
            ->orderBy('id')
            ->get();
        self::assertCount(2, $lockAudits);
        self::assertSame(['method' => 'primary'], $lockAudits[0]->context);
        self::assertSame(['method' => 'step_up', 'action' => $actionC], $lockAudits[1]->context);
        $auditDump = json_encode($lockAudits->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('WRONG-RECOVERY', $auditDump);
        self::assertStringNotContainsString('credential-fixture', $auditDump);
        self::assertStringNotContainsString('session_binding', $auditDump);
        self::assertStringNotContainsString('code_hash', $auditDump);

        $tamperedAction = 'ticket.publish';
        $tamperedScope = 'step-up:'.$tamperedAction;
        Route::middleware(['web', 'auth'])->post('/_testing/tamper-strong-attempts', function (
            Request $request,
        ) use ($tamperedScope, $user) {
            $request->session()->put('ai6.auth.strong_attempts', [
                hash('sha256', $tamperedScope) => [
                    'user_id' => [(int) $user->getKey()],
                    'scope' => $tamperedScope,
                    'session_binding' => ['tampered-session-binding'],
                    'attempt_count' => 0,
                ],
            ]);

            return response()->noContent();
        });
        $this->post('/_testing/tamper-strong-attempts')->assertNoContent();
        $this->post(route('auth.step-up.totp.verify', ['action' => $tamperedAction]), [
            'code' => '000000',
        ])->assertSessionHasErrors('authentication');
    }

    public function test_strong_attempt_session_scopes_are_hard_bounded(): void
    {
        config([
            'ai6.auth.strong_authentication_max_attempts' => '20',
            'ai6.auth.strong_authentication_decay_seconds' => '300',
        ]);
        $this->app->forgetInstance(AuthConfiguration::class);
        $user = $this->createUser();
        $request = $this->sessionRequest('bounded-strong-attempt-scopes');
        $limiter = $this->app->make(StrongAuthenticationAttemptLimiter::class);

        for ($index = 0; $index <= StrongAuthenticationAttemptLimiter::SESSION_SCOPE_LIMIT + 4; $index++) {
            $limiter->recordFailure($request, $user, 'step-up:fixture.'.$index);
        }

        $records = $request->session()->get('ai6.auth.strong_attempts');
        self::assertIsArray($records);
        self::assertCount(StrongAuthenticationAttemptLimiter::SESSION_SCOPE_LIMIT, $records);
        self::assertArrayNotHasKey(hash('sha256', 'step-up:fixture.0'), $records);
        self::assertArrayHasKey(hash('sha256', 'step-up:fixture.5'), $records);
        self::assertArrayHasKey(
            hash('sha256', 'step-up:fixture.'.(StrongAuthenticationAttemptLimiter::SESSION_SCOPE_LIMIT + 4)),
            $records,
        );
        self::assertSame(1, AuthenticationAuditEntry::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'strong_authentication_locked')
            ->count());
    }

    public function test_http_critical_action_rejects_missing_expired_and_consumed_step_up_generically(): void
    {
        config(['ai6.auth.step_up_window_seconds' => '2']);
        $this->app->forgetInstance(AuthConfiguration::class);
        $user = $this->createUser();
        $secret = $this->createConfirmedTotp($user);
        $effect = (object) ['count' => 0];
        Route::middleware(['web', 'auth'])->post('/_testing/critical/{action}', function (
            Request $request,
            StepUpGuard $guard,
            string $action,
        ) use ($effect) {
            $principal = $request->user();
            self::assertInstanceOf(User::class, $principal);
            $guard->consumeFresh($request, $principal, $action);
            $effect->count++;

            return response()->json(['status' => 'applied']);
        })->where('action', '[a-z][a-z0-9._-]{0,63}');
        $this->actingAs($user);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();

        $this->postJson('/_testing/critical/control_branch.change')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Eine frische Step-up-Bestätigung ist erforderlich.'])
            ->assertDontSee('Fresh step-up authentication is required.');
        $this->withHeader('Accept', 'text/html')->post('/_testing/critical/control_branch.change')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Eine frische Step-up-Bestätigung ist erforderlich.')
            ->assertDontSee('Fresh step-up authentication is required.');
        self::assertSame(0, $effect->count);

        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_branch.change']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertSessionHas('status', 'Step-up bestätigt.');
        $this->postJson('/_testing/critical/control_branch.change')
            ->assertOk()
            ->assertExactJson(['status' => 'applied']);
        $this->postJson('/_testing/critical/control_branch.change')->assertForbidden();
        self::assertSame(1, $effect->count);

        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_branch.change']), [
            'code' => $this->nextTotpCode($secret),
        ])->assertSessionHas('status', 'Step-up bestätigt.');
        $this->travel(3)->seconds();
        $this->postJson('/_testing/critical/control_branch.change')->assertForbidden();
        self::assertSame(1, $effect->count);
    }

    public function test_http_passkey_step_up_routes_create_one_action_bound_proof(): void
    {
        $this->app->instance(PasskeyCeremony::class, new HttpStepUpPasskeyCeremony);
        $user = $this->createUser();
        $this->createPasskey($user);
        $this->actingAs($user);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $action = 'control_branch.change';

        $this->postJson(route('auth.step-up.passkey.options', ['action' => $action]))
            ->assertOk()
            ->assertJsonPath('publicKey.rpId', 'localhost');
        $this->postJson(route('auth.step-up.passkey.verify', ['action' => $action]), [
            'credential_id' => 'credential-fixture',
            'client_data_json' => 'exact-origin',
            'authenticator_data' => 'valid-authenticator',
            'signature' => 'valid-signature',
            'user_handle' => null,
        ])->assertOk()->assertExactJson(['status' => 'ok']);

        $proofs = session('ai6.auth.step_up');
        self::assertIsArray($proofs);
        self::assertCount(1, $proofs);
        self::assertDatabaseHas('authentication_audit_entries', [
            'user_id' => $user->getKey(),
            'event' => 'step_up_satisfied',
        ]);
    }

    public function test_acknowledged_policy_reduction_skips_only_the_email_barrier_and_is_visible(): void
    {
        $policy = $this->customPolicy([
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value => false,
        ], true);
        $this->app->instance(SecurityPolicy::class, $policy);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'reduced-login@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas(
                AuthenticationSession::STATE_KEY,
                AuthenticationSession::STATE_AUTHORIZED,
            );
        Mail::assertNothingSent();

        $banner = $policy->bannerData()->toArray();
        self::assertSame('custom', $banner['profile']);
        self::assertTrue($banner['reduced_mode_acknowledged']);
        self::assertContains(
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value,
            $banner['disabled_measures'],
        );

        $doctorResult = (new SecurityPolicyDoctorCheck($policy))->run();
        self::assertTrue($doctorResult->passed);
        self::assertSame(
            'deaktiviert',
            $doctorResult->details['Maßnahme '.SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value],
        );
        self::assertSame('bestätigt', $doctorResult->details['Reduktionsbestätigung']);
        $providerSource = file_get_contents(base_path('app/AI6/Shared/AI6ServiceProvider.php')) ?: '';
        self::assertStringContainsString('new SecurityPolicyDoctorCheck($app->make(SecurityPolicy::class))', $providerSource);
    }

    public function test_policy_reduction_without_acknowledgement_is_rejected_and_step_up_can_only_be_disabled_there(): void
    {
        $configuration = config('ai6.security');
        self::assertIsArray($configuration);
        $configuration['profile'] = 'custom';
        $configuration['acknowledge_reduced_mode'] = 'false';
        $configuration['measures'][SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value] = 'false';
        $configuration['measures'][SecurityMeasure::REQUIRE_CRITICAL_ACTION_STEP_UP->value] = 'false';

        $inspection = $this->app->make(SecurityPolicyFactory::class)->inspect($configuration);
        self::assertInstanceOf(ConfigurationViolation::class, $inspection);
        self::assertStringContainsString('AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true', $inspection->message);

        $policy = $this->customPolicy([
            SecurityMeasure::REQUIRE_CRITICAL_ACTION_STEP_UP->value => false,
        ], true);
        $this->app->instance(SecurityPolicy::class, $policy);
        $guard = $this->app->make(StepUpGuard::class);
        $guard->consumeFresh(
            $this->sessionRequest('disabled-step-up'),
            $this->createUser(),
            'control_branch.change',
        );

        $source = file_get_contents(base_path('app/AI6/Auth/StepUpGuard.php')) ?: '';
        self::assertStringContainsString('SecurityPolicy', $source);
        self::assertStringContainsString('REQUIRE_CRITICAL_ACTION_STEP_UP', $source);
        self::assertStringNotContainsString("config('ai6.security", $source);
    }

    public function test_privileged_primary_authentication_policy_classifies_roles_and_keeps_its_reduction_visible(): void
    {
        $policy = $this->app->make(PrimaryAuthenticationPolicy::class);
        $project = $this->createProject();
        $globalAdministrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $unassigned = $this->createUser();
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);

        self::assertTrue($policy->isPrivileged($globalAdministrator));
        self::assertFalse($policy->isPrivileged($viewer));
        self::assertFalse($policy->isPrivileged($unassigned));
        self::assertTrue($policy->allows($viewer, PrimaryAuthenticationMethod::RECOVERY));
        self::assertTrue($policy->allows($unassigned, PrimaryAuthenticationMethod::RECOVERY));

        foreach ([ProjectRole::ADMIN, ProjectRole::OPERATOR, ProjectRole::APPROVER] as $role) {
            $user = $this->createUser();
            $this->addMembership($user, $project, $role);

            self::assertTrue($policy->isPrivileged($user), $role->value);
            self::assertTrue($policy->allows($user, PrimaryAuthenticationMethod::PASSKEY), $role->value);
            self::assertTrue($policy->allows($user, PrimaryAuthenticationMethod::TOTP), $role->value);
            self::assertFalse($policy->allows($user, PrimaryAuthenticationMethod::RECOVERY), $role->value);
        }

        self::assertFalse($policy->allows($globalAdministrator, PrimaryAuthenticationMethod::RECOVERY));

        $reducedSecurityPolicy = $this->customPolicy([
            SecurityMeasure::REQUIRE_PRIVILEGED_PASSKEY->value => false,
        ], true);
        $reducedPolicy = new PrimaryAuthenticationPolicy($reducedSecurityPolicy);
        self::assertTrue($reducedPolicy->allows($globalAdministrator, PrimaryAuthenticationMethod::RECOVERY));
        self::assertContains(
            SecurityMeasure::REQUIRE_PRIVILEGED_PASSKEY,
            $reducedSecurityPolicy->bannerData()->disabledMeasures,
        );
    }

    public function test_privileged_recovery_code_is_neither_offered_nor_consumed(): void
    {
        $plainCode = 'ABCD-EF12-3456-7890';
        $user = $this->createUser([
            'email' => 'privileged-recovery@example.test',
            'password' => 'correct-password',
            'is_global_admin' => true,
        ]);
        $this->createConfirmedTotp($user);
        $recoveryCode = RecoveryCode::query()->create([
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make($plainCode),
            'issued_at' => now(),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();

        $this->get(route('auth.primary.factor'))
            ->assertOk()
            ->assertDontSee('Recovery-Code verwenden');
        $this->post(route('auth.primary.recovery.verify'), ['code' => $plainCode])
            ->assertSessionHasErrors('code');

        self::assertNull($recoveryCode->fresh()->consumed_at);
        self::assertSame(
            AuthenticationSession::STATE_PRIMARY_PENDING,
            session(AuthenticationSession::STATE_KEY),
        );
    }

    /** @param array<string, bool> $overrides */
    private function customPolicy(array $overrides, bool $acknowledged): SecurityPolicy
    {
        $measures = array_fill_keys(
            array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                SecurityMeasure::cases(),
            ),
            true,
        );

        return new SecurityPolicy(
            SecurityProfile::CUSTOM,
            array_replace($measures, $overrides),
            $acknowledged,
        );
    }

    private function assertStepUpRejected(
        StepUpGuard $guard,
        Request $request,
        User $user,
        string $action,
    ): void {
        try {
            $guard->consumeFresh($request, $user, $action);
            self::fail('A missing, stale or mismatched step-up proof must be rejected.');
        } catch (StepUpRequiredException) {
        }
    }

    private function sessionRequest(string $id): Request
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId($id);
        $session->start();
        $request = Request::create('/auth/step-up', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }

    private function wrongTotpCode(string $secret): string
    {
        $valid = $this->currentTotpCode($secret);

        return $valid === '000000' ? '111111' : '000000';
    }

    private function nextTotpCode(string $secret): string
    {
        $google2fa = $this->app->make(Google2FA::class);
        $oathTotp = new ReflectionMethod($google2fa, 'oathTotp');
        $code = $oathTotp->invoke($google2fa, $secret, $google2fa->getTimestamp() + 1);
        self::assertIsString($code);

        return $code;
    }
}

final class HttpStepUpPasskeyCeremony implements PasskeyCeremony
{
    public function registrationOptions(User $user, array $excludedCredentialIds): PasskeyOptions
    {
        throw new PasskeyCeremonyRejectedException;
    }

    public function authenticationOptions(array $allowedCredentialIds): PasskeyOptions
    {
        return new PasskeyOptions([
            'challenge' => 'step-up-challenge',
            'rpId' => 'localhost',
        ], 'step-up-challenge');
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
        if ($challenge !== 'step-up-challenge'
            || ($response['client_data_json'] ?? null) !== 'exact-origin'
            || ($response['signature'] ?? null) !== 'valid-signature'
            || $credential->user_id !== $user->getKey()) {
            throw new PasskeyCeremonyRejectedException;
        }

        return $credential->signature_counter + 1;
    }
}
