<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Jobs\SendLoginConfirmationMail;
use App\AI6\Auth\LoginConfirmationDeliveryStatus;
use App\AI6\Auth\LoginConfirmationManager;
use App\AI6\Auth\LoginConfirmationVerification;
use App\AI6\Auth\Mail\LoginConfirmationMail;
use App\AI6\Auth\Models\AuthenticationAuditEntry;
use App\AI6\Auth\Models\LoginConfirmation;
use App\AI6\Auth\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class LoginConfirmationFlowTest extends AuthFeatureTestCase
{
    private const RECIPIENT = 'security@example.test';

    public function test_strict_login_stays_blocked_until_the_same_browser_confirms_the_mailed_code(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        Mail::fake();
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
        $user = $this->createUser([
            'email' => 'confirmed-login@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);

        $code = $this->loginThroughTotp($user, 'correct-password', $secret);

        $this->assertAuthenticatedAs($user);
        self::assertSame(
            AuthenticationSession::STATE_EMAIL_PENDING,
            session(AuthenticationSession::STATE_KEY),
        );
        $this->get(route('projects.index'))->assertRedirect(route('auth.confirmation.show'));

        $confirmation = LoginConfirmation::query()->sole();
        self::assertSame(LoginConfirmationDeliveryStatus::SENT->value, $confirmation->delivery_status);
        self::assertSame(64, strlen($confirmation->code_digest));
        self::assertSame(64, strlen($confirmation->recipient_digest));
        self::assertSame(64, strlen($confirmation->session_digest));
        self::assertStringNotContainsString($code, json_encode($confirmation->getAttributes(), JSON_THROW_ON_ERROR));

        $this->post(route('auth.confirmation.verify'), ['code' => $code])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas(
                AuthenticationSession::STATE_KEY,
                AuthenticationSession::STATE_AUTHORIZED,
            );
        $this->get(route('projects.index'))->assertOk();
        self::assertNotNull($confirmation->fresh()->consumed_at);
        self::assertStringNotContainsString($code, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_wrong_expired_and_consumed_codes_never_create_a_new_authorization(): void
    {
        $this->configureConfirmation(self::RECIPIENT, maxAttempts: 2);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'attempts@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $validCode = $this->loginThroughTotp($user, 'correct-password', $secret);

        $this->post(route('auth.confirmation.verify'), ['code' => '00000000'])
            ->assertSessionHasErrors('code');
        self::assertNotSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));

        $this->post(route('auth.confirmation.verify'), ['code' => '11111111'])
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $locked = LoginConfirmation::query()->sole();
        self::assertSame(2, $locked->attempt_count);
        self::assertNotNull($locked->invalidated_at);
        $lockAudit = AuthenticationAuditEntry::query()
            ->where('user_id', $user->getKey())
            ->where('event', 'login_confirmation_locked')
            ->sole();
        self::assertSame(['method' => 'email_confirmation'], $lockAudit->context);
        $lockAuditPayload = json_encode($lockAudit->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($validCode, $lockAuditPayload);
        self::assertStringNotContainsString('00000000', $lockAuditPayload);
        self::assertStringNotContainsString('11111111', $lockAuditPayload);

        $secondUser = $this->createUser([
            'email' => 'expired@example.test',
            'password' => 'correct-password',
        ]);
        $secondSecret = $this->createConfirmedTotp($secondUser);
        $expiredCode = $this->loginThroughTotp($secondUser, 'correct-password', $secondSecret);
        $this->travel(601)->seconds();

        $this->post(route('auth.confirmation.verify'), ['code' => $expiredCode])
            ->assertSessionHasErrors('code');
        self::assertNotSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));

        $request = request();
        self::assertSame(
            LoginConfirmationVerification::UNAVAILABLE,
            $this->app->make(LoginConfirmationManager::class)->verify($request, $secondUser, $validCode),
        );
    }

    public function test_confirmation_is_bound_to_one_browser_session(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        Mail::fake();
        $user = $this->createUser();
        $manager = $this->app->make(LoginConfirmationManager::class);
        $requestA = $this->sessionRequest('browser-a');
        $issue = $manager->issue($requestA, $user);
        $requestA->session()->put('ai6.auth.email', [
            'challenge_id' => $issue->confirmation->id,
            'revision' => $issue->confirmation->revision,
        ]);
        $code = $this->lastMailedCode(self::RECIPIENT);

        $requestB = $this->sessionRequest('browser-b');
        $requestB->session()->put('ai6.auth.email', $requestA->session()->get('ai6.auth.email'));

        self::assertSame(
            LoginConfirmationVerification::UNAVAILABLE,
            $manager->verify($requestB, $user, $code),
        );
        self::assertSame(
            LoginConfirmationVerification::SUCCESS,
            $manager->verify($requestA, $user, $code),
        );
        self::assertSame(
            LoginConfirmationVerification::UNAVAILABLE,
            $manager->verify($requestA, $user, $code),
        );
    }

    public function test_parallel_browser_confirmations_do_not_invalidate_each_other(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        Mail::fake();
        $user = $this->createUser();
        $manager = $this->app->make(LoginConfirmationManager::class);
        $requestA = $this->sessionRequest('parallel-browser-a');
        $issueA = $manager->issue($requestA, $user);
        $requestA->session()->put('ai6.auth.email', [
            'challenge_id' => $issueA->confirmation->id,
            'revision' => $issueA->confirmation->revision,
        ]);
        $codeA = $this->lastMailedCode(self::RECIPIENT);
        $requestB = $this->sessionRequest('parallel-browser-b');
        $issueB = $manager->issue($requestB, $user);
        $requestB->session()->put('ai6.auth.email', [
            'challenge_id' => $issueB->confirmation->id,
            'revision' => $issueB->confirmation->revision,
        ]);
        $codeB = $this->lastMailedCode(self::RECIPIENT);

        self::assertNull($issueA->confirmation->fresh()->invalidated_at);
        self::assertNull($issueB->confirmation->fresh()->invalidated_at);
        self::assertSame(1, $issueA->confirmation->revision);
        self::assertSame(1, $issueB->confirmation->revision);
        self::assertSame(LoginConfirmationVerification::SUCCESS, $manager->verify($requestA, $user, $codeA));
        self::assertSame(LoginConfirmationVerification::SUCCESS, $manager->verify($requestB, $user, $codeB));
    }

    public function test_authorized_sessions_cannot_reenter_pre_authorization_routes(): void
    {
        $this->configureConfirmation(self::RECIPIENT, resendCooldown: 1);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'authorized-route@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $code = $this->loginThroughTotp($user, 'correct-password', $secret);
        $this->post(route('auth.confirmation.verify'), ['code' => $code])
            ->assertRedirect(route('projects.index'));
        $confirmationCount = LoginConfirmation::query()->count();

        $this->travel(2)->seconds();
        $this->post(route('auth.confirmation.resend'))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas(AuthenticationSession::STATE_KEY, AuthenticationSession::STATE_AUTHORIZED);
        $this->get(route('auth.primary.factor'))->assertRedirect(route('projects.index'));
        $this->get(route('auth.enrollment.totp.show'))->assertRedirect(route('projects.index'));
        $this->postJson(route('auth.confirmation.resend'))
            ->assertConflict()
            ->assertExactJson(['message' => 'Authentifizierung bereits abgeschlossen.']);

        self::assertSame($confirmationCount, LoginConfirmation::query()->count());
        self::assertSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));
    }

    public function test_missing_primary_method_after_email_confirmation_fails_closed_without_http_500(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'missing-primary-method@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $code = $this->loginThroughTotp($user, 'correct-password', $secret);
        $session = $this->app->make('session')->driver();
        $session->forget('ai6.auth.primary_method');
        $session->save();

        $this->post(route('auth.confirmation.verify'), ['code' => $code])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        self::assertNotNull(LoginConfirmation::query()->sole()->consumed_at);
        self::assertDatabaseMissing('authentication_audit_entries', [
            'user_id' => $user->getKey(),
            'event' => 'login_authorized',
        ]);
    }

    public function test_resend_invalidates_the_previous_revision_and_a_late_old_job_cannot_revive_it(): void
    {
        $this->configureConfirmation(self::RECIPIENT, resendCooldown: 1);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'resend@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $oldCode = $this->loginThroughTotp($user, 'correct-password', $secret);
        $old = LoginConfirmation::query()->sole();

        $this->travel(2)->seconds();
        $this->post(route('auth.confirmation.resend'))->assertRedirect(route('auth.confirmation.show'));
        $newCode = $this->lastMailedCode(self::RECIPIENT);
        self::assertNotSame($oldCode, $newCode);

        $old->refresh();
        $new = LoginConfirmation::query()->whereKeyNot($old->id)->sole();
        self::assertSame($old->revision + 1, $new->revision);
        self::assertNotNull($old->invalidated_at);
        $this->get(route('auth.confirmation.show'))
            ->assertOk()
            ->assertSee('Aktuelle Code-Version: '.$new->revision)
            ->assertSee('macht alle älteren E-Mails ungültig');
        $mail = Mail::sent(LoginConfirmationMail::class)->last();
        self::assertInstanceOf(LoginConfirmationMail::class, $mail);
        self::assertSame(
            'AI6 Anmeldebestätigung – Code-Version '.$new->revision,
            $mail->envelope()->subject,
        );
        self::assertStringContainsString(
            'Code-Version '.$new->revision,
            $mail->render(),
        );

        $old->forceFill(['delivery_status' => LoginConfirmationDeliveryStatus::QUEUED->value])->save();
        (new SendLoginConfirmationMail($old->id, $old->revision, self::RECIPIENT, $oldCode))->handle();
        self::assertSame(LoginConfirmationDeliveryStatus::QUEUED->value, $old->fresh()->delivery_status);

        $this->post(route('auth.confirmation.verify'), ['code' => $oldCode])
            ->assertSessionHasErrors('code');
        $this->post(route('auth.confirmation.verify'), ['code' => $newCode])
            ->assertRedirect(route('projects.index'));
    }

    public function test_recipient_change_invalidates_every_open_confirmation(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'recipient-change@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);
        $code = $this->loginThroughTotp($user, 'correct-password', $secret);
        $confirmation = LoginConfirmation::query()->sole();

        $this->configureConfirmation('replacement@example.test');
        $this->get(route('projects.index'))->assertRedirect(route('auth.confirmation.show'));

        self::assertNotNull($confirmation->fresh()->invalidated_at);
        $this->post(route('auth.confirmation.verify'), ['code' => $code])
            ->assertSessionHasErrors('code');
        self::assertNotSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));
    }

    public function test_missing_recipient_stays_fail_closed_without_attempting_mail_delivery(): void
    {
        $this->configureConfirmation(null);
        Mail::fake();
        $user = $this->createUser([
            'email' => 'missing-recipient@example.test',
            'password' => 'correct-password',
        ]);
        $secret = $this->createConfirmedTotp($user);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-password']);
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertRedirect(route('auth.confirmation.show'));
        Mail::assertNothingSent();
        $missing = LoginConfirmation::query()->sole();
        self::assertSame(LoginConfirmationDeliveryStatus::FAILED->value, $missing->delivery_status);
        self::assertSame('recipient_unavailable', $missing->failure_key);
        $this->get(route('auth.confirmation.show'))
            ->assertOk()
            ->assertSee('Sitzung bleibt gesperrt');
    }

    public function test_mail_transport_failure_stays_fail_closed_with_safe_diagnostics(): void
    {
        $this->configureConfirmation(self::RECIPIENT);
        $safeLogger = new class
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $warnings = [];

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->warnings[] = [$message, $context];
            }
        };
        Log::swap($safeLogger);
        Mail::swap(new class(self::RECIPIENT)
        {
            public function __construct(private readonly string $expectedRecipient) {}

            public function to(string $recipient): self
            {
                if ($recipient !== $this->expectedRecipient) {
                    throw new RuntimeException('unexpected-recipient');
                }

                return $this;
            }

            public function send(object $mail): never
            {
                throw new RuntimeException('private-transport-value');
            }
        });
        $transportUser = $this->createUser([
            'email' => 'mail-failure@example.test',
            'password' => 'correct-password',
        ]);
        $transportSecret = $this->createConfirmedTotp($transportUser);

        $this->post(route('login.store'), [
            'email' => $transportUser->email,
            'password' => 'correct-password',
        ]);
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), [
            'code' => $this->currentTotpCode($transportSecret),
        ]);

        $failed = LoginConfirmation::query()->where('user_id', $transportUser->getKey())->sole();
        self::assertSame(LoginConfirmationDeliveryStatus::FAILED->value, $failed->delivery_status);
        self::assertSame('mail_transport_failed', $failed->failure_key);
        self::assertNotSame(AuthenticationSession::STATE_AUTHORIZED, session(AuthenticationSession::STATE_KEY));
        self::assertCount(1, $safeLogger->warnings);
        self::assertSame('Login confirmation delivery failed.', $safeLogger->warnings[0][0]);
        self::assertStringNotContainsString(
            'private-transport-value',
            json_encode($safeLogger->warnings[0][1], JSON_THROW_ON_ERROR),
        );
    }

    public function test_non_delivering_mail_transport_is_rejected_before_receiving_the_code(): void
    {
        config(['mail.default' => 'log']);
        Mail::fake();
        $safeLogger = new class
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $warnings = [];

            /** @param array<string, mixed> $context */
            public function warning(string $message, array $context = []): void
            {
                $this->warnings[] = [$message, $context];
            }
        };
        Log::swap($safeLogger);
        $user = $this->createUser();
        $confirmation = LoginConfirmation::query()->create([
            'id' => 'fcdbeacf-c173-449b-bb4f-f478671c84a2',
            'user_id' => $user->getKey(),
            'revision' => 1,
            'code_digest' => str_repeat('a', 64),
            'recipient_digest' => str_repeat('b', 64),
            'session_digest' => str_repeat('c', 64),
            'expires_at' => now()->addMinutes(10),
            'attempt_count' => 0,
            'delivery_status' => LoginConfirmationDeliveryStatus::QUEUED->value,
            'delivery_status_changed_at' => now(),
        ]);
        $code = '34821975';

        (new SendLoginConfirmationMail($confirmation->id, 1, self::RECIPIENT, $code))->handle();

        Mail::assertNothingSent();
        self::assertSame(LoginConfirmationDeliveryStatus::FAILED->value, $confirmation->fresh()->delivery_status);
        self::assertSame('mail_transport_not_deliverable', $confirmation->fresh()->failure_key);
        self::assertCount(1, $safeLogger->warnings);
        self::assertStringNotContainsString(
            $code,
            json_encode($safeLogger->warnings, JSON_THROW_ON_ERROR),
        );
    }

    public function test_database_queue_payload_contains_only_an_encrypted_command_envelope(): void
    {
        $user = $this->createUser();
        $confirmation = LoginConfirmation::query()->create([
            'id' => '2e26e5a4-18b3-4d25-aa0d-b232d382380e',
            'user_id' => $user->getKey(),
            'revision' => 1,
            'code_digest' => str_repeat('a', 64),
            'recipient_digest' => str_repeat('b', 64),
            'session_digest' => str_repeat('c', 64),
            'expires_at' => now()->addMinutes(10),
            'attempt_count' => 0,
            'delivery_status' => LoginConfirmationDeliveryStatus::QUEUED->value,
            'delivery_status_changed_at' => now(),
        ]);
        $code = '73519462';
        $job = new SendLoginConfirmationMail($confirmation->id, 1, self::RECIPIENT, $code);

        Queue::connection('database')->push($job);

        $rawPayload = (string) $this->app->make('db')->table('jobs')->value('payload');
        self::assertTrue($this->payloadProtectsCode($rawPayload, $code));
        $payload = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(SendLoginConfirmationMail::class, $payload['data']['commandName']);
        self::assertIsString($payload['data']['command']);

        $serialized = $this->app->make(Encrypter::class)->decrypt($payload['data']['command']);
        self::assertIsString($serialized);
        self::assertStringContainsString($code, $serialized);
        self::assertStringContainsString($code, serialize($job));

        Queue::connection('database')->push(new UnencryptedLoginConfirmationComparisonJob($code));
        $payloads = $this->app->make('db')->table('jobs')->orderBy('id')->pluck('payload')->all();
        self::assertCount(2, $payloads);
        self::assertIsString($payloads[1]);
        self::assertFalse($this->payloadProtectsCode($payloads[1], $code));
        self::assertStringContainsString($code, $payloads[1]);
    }

    private function configureConfirmation(
        ?string $recipient,
        int $maxAttempts = 5,
        int $resendCooldown = 30,
    ): void {
        config([
            'ai6.auth.login_confirmation_email' => $recipient,
            'ai6.auth.login_confirmation_ttl_seconds' => '600',
            'ai6.auth.login_confirmation_max_attempts' => (string) $maxAttempts,
            'ai6.auth.login_confirmation_resend_cooldown_seconds' => (string) $resendCooldown,
        ]);
        $this->app->forgetInstance(AuthConfiguration::class);
    }

    private function loginThroughTotp(User $user, string $password, string $secret): string
    {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])
            ->assertRedirect(route('auth.primary.factor'));
        $this->preserveCurrentSessionCookie();
        $this->post(route('auth.primary.totp.verify'), ['code' => $this->currentTotpCode($secret)])
            ->assertRedirect(route('auth.confirmation.show'));

        return $this->lastMailedCode(self::RECIPIENT);
    }

    private function lastMailedCode(string $recipient): string
    {
        $mail = Mail::sent(LoginConfirmationMail::class)->last();
        self::assertInstanceOf(LoginConfirmationMail::class, $mail);
        self::assertTrue($mail->hasTo($recipient));
        $rendered = $mail->render();
        self::assertMatchesRegularExpression('/\b\d{8}\b/', $rendered);
        self::assertDoesNotMatchRegularExpression('/https?:\/\//i', $rendered);
        self::assertSame(1, preg_match('/\b(\d{8})\b/', $rendered, $matches));

        return $matches[1];
    }

    private function sessionRequest(string $id): Request
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId($id);
        $session->start();
        $request = Request::create('/auth/confirmation', 'POST');
        $request->setLaravelSession($session);

        return $request;
    }

    private function payloadProtectsCode(string $payload, string $code): bool
    {
        return ! str_contains($payload, $code)
            && ! str_contains($payload, '"code"');
    }
}

final class UnencryptedLoginConfirmationComparisonJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code) {}

    public function handle(): void {}
}
