<?php

namespace Tests\Unit\Auth;

use App\AI6\Auth\AuthenticationHmac;
use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Base64Url;
use App\AI6\Auth\LbuchsPasskeyCeremony;
use App\AI6\Auth\LoginConfirmationManager;
use App\AI6\Auth\PasskeyCeremonyRejectedException;
use App\AI6\Auth\PasskeyRelyingParty;
use App\AI6\Auth\PasskeyRelyingPartyFactory;
use App\AI6\Auth\TotpSecretAuthenticationException;
use App\AI6\Auth\TotpSecretCipher;
use App\AI6\Shared\Config\ConfigurationException;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use lbuchs\WebAuthn\WebAuthn;
use Psr\Log\AbstractLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Tests\TestCase;
use UnexpectedValueException;

final class StrongAuthenticationPrimitivesTest extends TestCase
{
    public function test_login_confirmation_code_length_is_fixed_in_code_only(): void
    {
        self::assertSame(8, LoginConfirmationManager::CODE_LENGTH);
        self::assertStringNotContainsString('CODE_LENGTH', file_get_contents(base_path('config/ai6.php')) ?: '');
        self::assertStringNotContainsString('CODE_LENGTH', file_get_contents(base_path('.env.example')) ?: '');
        self::assertStringNotContainsString('CONFIRMATION_CODE_LENGTH', file_get_contents(base_path('config/ai6.php')) ?: '');
        self::assertStringNotContainsString('CONFIRMATION_CODE_LENGTH', file_get_contents(base_path('.env.example')) ?: '');
    }

    public function test_authentication_hmac_uses_the_shared_canonical_byte_frame(): void
    {
        $hmac = $this->app->make(AuthenticationHmac::class);

        self::assertSame(
            $hmac->digest('AI6-AUTH-TEST-V1', ["e\u{0301}", 7]),
            $hmac->digest('AI6-AUTH-TEST-V1', ['é', '7']),
        );
        self::assertStringContainsString(
            'CanonicalByteFrame::encode',
            file_get_contents(base_path('app/AI6/Auth/AuthenticationHmac.php')) ?: '',
        );

        $this->expectException(InvalidArgumentException::class);
        $hmac->digest("invalid\ndomain", ['value']);
    }

    public function test_totp_secret_uses_authenticated_encryption_and_tampering_has_a_safe_error(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $cipher = $this->app->make(TotpSecretCipher::class);
        $encrypted = $cipher->encrypt($secret);

        self::assertNotSame($secret, $encrypted);
        self::assertSame($secret, $cipher->decrypt($encrypted));

        $replacement = $encrypted[strlen($encrypted) - 1] === 'A' ? 'B' : 'A';
        $tampered = substr($encrypted, 0, -1).$replacement;

        try {
            $cipher->decrypt($tampered);
            self::fail('A tampered TOTP ciphertext must be rejected.');
        } catch (TotpSecretAuthenticationException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringContainsString('authenticity', $exception->getMessage());
        }
    }

    public function test_login_completion_gate_is_the_only_authorized_session_writer(): void
    {
        $matches = [];

        foreach ($this->authPhpFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            if (preg_match(
                '/->put\(\s*AuthenticationSession::STATE_KEY\s*,\s*AuthenticationSession::STATE_AUTHORIZED\s*\)/s',
                $source,
            ) === 1) {
                $matches[] = str_replace('\\', '/', $file);
            }
        }

        self::assertSame(
            [str_replace('\\', '/', base_path('app/AI6/Auth/LoginCompletionGate.php'))],
            $matches,
        );
        self::assertSame('authorized', AuthenticationSession::STATE_AUTHORIZED);
    }

    public function test_there_is_exactly_one_password_bound_enrollment_entry_point(): void
    {
        $callers = [];

        foreach ($this->authPhpFiles() as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            if (str_contains($source, '$enrollment->start(')) {
                $callers[] = str_replace('\\', '/', $file);
            }
        }

        self::assertSame(
            [str_replace('\\', '/', base_path('app/AI6/Auth/Http/LoginController.php'))],
            $callers,
        );
    }

    public function test_passkey_relying_party_and_origin_match_the_configured_origin_exactly(): void
    {
        config([
            'app.name' => 'AI6 Test',
            'app.url' => 'https://Example.Test:8443/ignored-base-path',
        ]);
        $relyingParty = (new PasskeyRelyingPartyFactory)->fromConfiguredValues();
        self::assertSame('AI6 Test', $relyingParty->name);
        self::assertSame('example.test', $relyingParty->id);
        self::assertSame('https://example.test:8443', $relyingParty->origin);

        $method = new ReflectionMethod(LbuchsPasskeyCeremony::class, 'assertExactOrigin');
        $ceremony = new LbuchsPasskeyCeremony($relyingParty);
        $method->invoke($ceremony, json_encode([
            'type' => 'webauthn.get',
            'origin' => 'https://example.test:8443',
        ], JSON_THROW_ON_ERROR));

        try {
            $method->invoke($ceremony, json_encode([
                'type' => 'webauthn.get',
                'origin' => 'https://sub.example.test:8443',
            ], JSON_THROW_ON_ERROR));
            self::fail('A different passkey origin must be rejected.');
        } catch (UnexpectedValueException) {
        }

        config(['app.url' => 'http://example.test']);
        $this->expectException(ConfigurationException::class);
        (new PasskeyRelyingPartyFactory)->fromConfiguredValues();
    }

    public function test_http_localhost_is_the_only_non_https_origin_supported_by_the_passkey_verifier(): void
    {
        config(['app.name' => 'AI6 Test']);

        foreach (['http://localhost', 'http://localhost:8080'] as $supportedOrigin) {
            config(['app.url' => $supportedOrigin]);

            self::assertEquals(
                new PasskeyRelyingParty('AI6 Test', 'localhost', $supportedOrigin),
                (new PasskeyRelyingPartyFactory)->fromConfiguredValues(),
            );
        }

        foreach (['http://127.0.0.1:8080', 'http://[::1]:8080'] as $unsupportedOrigin) {
            config(['app.url' => $unsupportedOrigin]);

            try {
                (new PasskeyRelyingPartyFactory)->fromConfiguredValues();
                self::fail('The selected WebAuthn verifier accepts local HTTP only with the localhost RP ID.');
            } catch (ConfigurationException) {
            }
        }
    }

    public function test_passkey_rejection_logs_only_a_safe_operation_and_reason(): void
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

        try {
            $ceremony->verifyRegistration([
                'client_data_json' => 'not*valid',
                'attestation_object' => 'not*valid',
            ], 'challenge');
            self::fail('Invalid registration input must be rejected.');
        } catch (PasskeyCeremonyRejectedException) {
        }

        self::assertSame([
            [
                'level' => 'warning',
                'message' => 'Passkey ceremony rejected.',
                'context' => [
                    'operation' => 'registration',
                    'reason' => 'invalid_encoding',
                ],
            ],
        ], $logger->records);
    }

    public function test_passkey_registration_maps_the_library_binary_string_contract(): void
    {
        $ceremony = new LbuchsPasskeyCeremony(
            new PasskeyRelyingParty('AI6 Test', 'localhost', 'http://localhost:8000'),
        );
        $challenge = 'deterministic-registration-challenge';
        $response = RealWebAuthnFixture::registrationResponse($challenge, 'http://localhost:8000');
        $fixtureSource = file_get_contents(__DIR__.'/RealWebAuthnFixture.php');
        self::assertIsString($fixtureSource);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $fixtureSource);
        self::assertStringNotContainsString('PRIVATE_KEY_PEM', $fixtureSource);
        $library = new WebAuthn('AI6 Test', 'localhost', ['none'], true);
        $libraryResult = $library->processCreate(
            Base64Url::decode($response['client_data_json']),
            Base64Url::decode($response['attestation_object']),
            $challenge,
            true,
            true,
            false,
            false,
        );

        self::assertSame('v2.2.0', InstalledVersions::getPrettyVersion('lbuchs/webauthn'));
        self::assertIsString($libraryResult->credentialId);
        self::assertSame(RealWebAuthnFixture::credentialId(), $libraryResult->credentialId);

        $registration = $ceremony->verifyRegistration($response, Base64Url::encode($challenge));

        self::assertSame(Base64Url::encode(RealWebAuthnFixture::credentialId()), $registration->credentialId);
        self::assertNotSame(RealWebAuthnFixture::credentialId(), $registration->credentialId);
        self::assertStringContainsString('BEGIN PUBLIC KEY', $registration->publicKey);
        self::assertSame(RealWebAuthnFixture::REGISTRATION_COUNTER, $registration->signatureCounter);
    }

    /** @return list<string> */
    private function authPhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/AI6/Auth')));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
