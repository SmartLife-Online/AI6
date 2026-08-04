<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\PasskeyCredential;
use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Throwable;
use UnexpectedValueException;

final readonly class LbuchsPasskeyCeremony implements PasskeyCeremony
{
    public function __construct(private PasskeyRelyingParty $relyingParty) {}

    public function registrationOptions(User $user, array $excludedCredentialIds): PasskeyOptions
    {
        try {
            $webAuthn = $this->server();
            $options = $webAuthn->getCreateArgs(
                (string) $user->getKey(),
                $user->email,
                $user->name,
                PasskeyCeremony::TIMEOUT_SECONDS,
                true,
                true,
                null,
                array_map(Base64Url::decode(...), $excludedCredentialIds),
            );

            return new PasskeyOptions(
                $this->toArray($options->publicKey),
                Base64Url::encode($webAuthn->getChallenge()->getBinaryString()),
            );
        } catch (Throwable $exception) {
            $this->reject('registration', $exception);
        }
    }

    public function authenticationOptions(array $allowedCredentialIds): PasskeyOptions
    {
        try {
            $webAuthn = $this->server();
            $options = $webAuthn->getGetArgs(
                array_map(Base64Url::decode(...), $allowedCredentialIds),
                PasskeyCeremony::TIMEOUT_SECONDS,
                true,
                true,
                true,
                true,
                true,
                true,
            );

            return new PasskeyOptions(
                $this->toArray($options->publicKey),
                Base64Url::encode($webAuthn->getChallenge()->getBinaryString()),
            );
        } catch (Throwable $exception) {
            $this->reject('authentication', $exception);
        }
    }

    public function verifyRegistration(array $response, string $challenge): PasskeyRegistration
    {
        try {
            $clientData = $this->decodeField($response, 'client_data_json');
            $this->assertExactOrigin($clientData);
            $result = $this->server()->processCreate(
                $clientData,
                $this->decodeField($response, 'attestation_object'),
                Base64Url::decode($challenge),
                true,
                true,
                false,
                false,
            );

            return $this->registrationFromLibraryResult($result);
        } catch (Throwable $exception) {
            $this->reject('registration', $exception);
        }
    }

    public function verifyAuthentication(
        PasskeyCredential $credential,
        User $user,
        array $response,
        string $challenge,
    ): int {
        try {
            $credentialId = $this->stringField($response, 'credential_id');
            if (! hash_equals($credential->credential_id, $credentialId)) {
                throw new UnexpectedValueException;
            }

            $userHandle = $response['user_handle'] ?? null;
            if (is_string($userHandle) && $userHandle !== ''
                && ! hash_equals((string) $user->getKey(), Base64Url::decode($userHandle))) {
                throw new UnexpectedValueException;
            }

            $clientData = $this->decodeField($response, 'client_data_json');
            $this->assertExactOrigin($clientData);
            $webAuthn = $this->server();
            $webAuthn->processGet(
                $clientData,
                $this->decodeField($response, 'authenticator_data'),
                $this->decodeField($response, 'signature'),
                $credential->credential_public_key,
                Base64Url::decode($challenge),
                $credential->signature_counter,
                true,
                true,
            );

            return $webAuthn->getSignatureCounter() ?? 0;
        } catch (Throwable $exception) {
            $this->reject('authentication', $exception);
        }
    }

    private function server(): WebAuthn
    {
        return new WebAuthn(
            $this->relyingParty->name,
            $this->relyingParty->id,
            ['none'],
            true,
        );
    }

    private function registrationFromLibraryResult(object $result): PasskeyRegistration
    {
        $credentialId = $result->credentialId ?? null;
        $publicKey = $result->credentialPublicKey ?? null;
        $signatureCounter = $result->signatureCounter ?? null;

        if (! is_string($credentialId) || $credentialId === ''
            || ! is_string($publicKey) || $publicKey === '') {
            throw new UnexpectedValueException;
        }

        return new PasskeyRegistration(
            Base64Url::encode($credentialId),
            $publicKey,
            is_int($signatureCounter) ? $signatureCounter : 0,
        );
    }

    /** @param array<string, mixed> $response */
    private function decodeField(array $response, string $key): string
    {
        return Base64Url::decode($this->stringField($response, $key));
    }

    /** @param array<string, mixed> $response */
    private function stringField(array $response, string $key): string
    {
        $value = $response[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnexpectedValueException;
        }

        return $value;
    }

    private function assertExactOrigin(string $clientDataJson): void
    {
        try {
            $clientData = json_decode($clientDataJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException;
        }

        if (! is_array($clientData)
            || ! is_string($clientData['origin'] ?? null)
            || ! hash_equals($this->relyingParty->origin, $clientData['origin'])) {
            throw new UnexpectedValueException;
        }
    }

    /** @return array<string, mixed> */
    private function toArray(object $value): array
    {
        try {
            $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException;
        }

        if (! is_array($decoded)) {
            throw new UnexpectedValueException;
        }

        return $decoded;
    }

    private function reject(string $operation, Throwable $exception): never
    {
        Log::warning('Passkey ceremony rejected.', [
            'operation' => $operation,
            'reason' => $this->safeReason($exception),
        ]);

        throw new PasskeyCeremonyRejectedException;
    }

    private function safeReason(Throwable $exception): string
    {
        if ($exception instanceof WebAuthnException) {
            return match ($exception->getCode()) {
                WebAuthnException::INVALID_DATA => 'invalid_data',
                WebAuthnException::INVALID_TYPE => 'invalid_type',
                WebAuthnException::INVALID_CHALLENGE => 'invalid_challenge',
                WebAuthnException::INVALID_ORIGIN => 'invalid_origin',
                WebAuthnException::INVALID_RELYING_PARTY => 'invalid_relying_party',
                WebAuthnException::INVALID_SIGNATURE => 'invalid_signature',
                WebAuthnException::INVALID_PUBLIC_KEY => 'invalid_public_key',
                WebAuthnException::CERTIFICATE_NOT_TRUSTED => 'certificate_not_trusted',
                WebAuthnException::USER_PRESENT => 'user_presence_missing',
                WebAuthnException::USER_VERIFICATED => 'user_verification_missing',
                WebAuthnException::SIGNATURE_COUNTER => 'invalid_signature_counter',
                WebAuthnException::CRYPTO_STRONG => 'cryptography_unavailable',
                WebAuthnException::BYTEBUFFER => 'invalid_binary_data',
                WebAuthnException::CBOR => 'invalid_cbor_data',
                WebAuthnException::ANDROID_NOT_TRUSTED => 'android_not_trusted',
                default => 'webauthn_rejected',
            };
        }

        return match (true) {
            $exception instanceof InvalidArgumentException => 'invalid_encoding',
            $exception instanceof JsonException, $exception instanceof UnexpectedValueException => 'invalid_response',
            default => 'internal_rejection',
        };
    }
}
