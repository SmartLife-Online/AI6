<?php

namespace Tests\Unit\Auth;

use App\AI6\Auth\Base64Url;
use OpenSSLAsymmetricKey;
use RuntimeException;

final class RealWebAuthnFixture
{
    public const REGISTRATION_COUNTER = 7;

    private static ?OpenSSLAsymmetricKey $privateKey = null;

    /** @return array<string, string> */
    public static function registrationResponse(string $challenge, string $origin): array
    {
        $clientData = self::clientData('webauthn.create', $challenge, $origin);
        $credentialId = self::credentialId();
        $details = openssl_pkey_get_details(self::privateKey());

        if (! is_array($details)
            || ! is_array($details['rsa'] ?? null)
            || ! is_string($details['rsa']['n'] ?? null)
            || ! is_string($details['rsa']['e'] ?? null)) {
            throw new RuntimeException('The WebAuthn fixture RSA key cannot be inspected.');
        }

        $coseKey = self::cborMap([
            [self::cborInteger(1), self::cborInteger(3)],
            [self::cborInteger(3), self::cborInteger(-257)],
            [self::cborInteger(-1), self::cborBytes($details['rsa']['n'])],
            [self::cborInteger(-2), self::cborBytes($details['rsa']['e'])],
        ]);
        $authenticatorData = hash('sha256', 'localhost', true)
            .chr(0x45)
            .pack('N', self::REGISTRATION_COUNTER)
            .str_repeat("\0", 16)
            .pack('n', strlen($credentialId))
            .$credentialId
            .$coseKey;
        $attestationObject = self::cborMap([
            [self::cborText('fmt'), self::cborText('none')],
            [self::cborText('attStmt'), self::cborMap([])],
            [self::cborText('authData'), self::cborBytes($authenticatorData)],
        ]);

        return [
            'credential_id' => Base64Url::encode($credentialId),
            'client_data_json' => Base64Url::encode($clientData),
            'attestation_object' => Base64Url::encode($attestationObject),
        ];
    }

    /** @return array<string, string|null> */
    public static function authenticationResponse(
        string $challenge,
        string $origin,
        int $signatureCounter,
    ): array {
        $clientData = self::clientData('webauthn.get', $challenge, $origin);
        $authenticatorData = hash('sha256', 'localhost', true)
            .chr(0x05)
            .pack('N', $signatureCounter);
        $signed = openssl_sign(
            $authenticatorData.hash('sha256', $clientData, true),
            $signature,
            self::privateKey(),
            OPENSSL_ALGO_SHA256,
        );

        if (! $signed || ! is_string($signature)) {
            throw new RuntimeException('The WebAuthn fixture assertion cannot be signed.');
        }

        return [
            'credential_id' => Base64Url::encode(self::credentialId()),
            'client_data_json' => Base64Url::encode($clientData),
            'authenticator_data' => Base64Url::encode($authenticatorData),
            'signature' => Base64Url::encode($signature),
            'user_handle' => null,
        ];
    }

    public static function credentialId(): string
    {
        $credentialId = hex2bin('0102feff00112233445566778899aabb');

        if (! is_string($credentialId)) {
            throw new RuntimeException('The WebAuthn fixture credential ID is invalid.');
        }

        return $credentialId;
    }

    private static function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Base64Url::encode($challenge),
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function privateKey(): OpenSSLAsymmetricKey
    {
        if (self::$privateKey instanceof OpenSSLAsymmetricKey) {
            return self::$privateKey;
        }

        /** @var array{private_key_bits: int, private_key_type: int, config?: string} $options */
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $bundledConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';

        if (is_file($bundledConfig)) {
            $options['config'] = $bundledConfig;
        }

        $privateKey = openssl_pkey_new($options);

        if (! $privateKey instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('The WebAuthn fixture key cannot be generated.');
        }

        self::$privateKey = $privateKey;

        return self::$privateKey;
    }

    /** @param list<array{string, string}> $pairs */
    private static function cborMap(array $pairs): string
    {
        $encoded = self::cborHead(5, count($pairs));

        foreach ($pairs as [$key, $value]) {
            $encoded .= $key.$value;
        }

        return $encoded;
    }

    private static function cborInteger(int $value): string
    {
        return $value >= 0
            ? self::cborHead(0, $value)
            : self::cborHead(1, -1 - $value);
    }

    private static function cborText(string $value): string
    {
        return self::cborHead(3, strlen($value)).$value;
    }

    private static function cborBytes(string $value): string
    {
        return self::cborHead(2, strlen($value)).$value;
    }

    private static function cborHead(int $majorType, int $value): string
    {
        $prefix = $majorType << 5;

        return match (true) {
            $value < 24 => chr($prefix | $value),
            $value <= 0xFF => chr($prefix | 24).pack('C', $value),
            $value <= 0xFFFF => chr($prefix | 25).pack('n', $value),
            default => chr($prefix | 26).pack('N', $value),
        };
    }
}
