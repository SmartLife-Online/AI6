<?php

namespace App\AI6\Shared\Redaction;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use JsonException;

final class RedactionKeyringFactory
{
    private const DERIVATION_DOMAIN = 'AI6-REDACTION-DERIVED-KEY-V1';

    private const KEY_ID_PATTERN = '/\A(?![0-9]+\z)[a-z0-9][a-z0-9._-]{0,63}\z/D';

    private const PURELY_NUMERIC_KEY_ID_PATTERN = '/\A[0-9]+\z/D';

    public function fromConfiguredValues(): RedactionKeyring
    {
        $configuration = config('ai6.redaction');

        if (! is_array($configuration)) {
            throw new ConfigurationException('Configuration key ai6.redaction must be an array.');
        }

        $result = $this->inspect($configuration);

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): RedactionKeyring|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.redaction must be an array.');
        }

        $activeKeyId = $configuration['active_key_id'] ?? null;

        if (is_string($activeKeyId) && preg_match(self::PURELY_NUMERIC_KEY_ID_PATTERN, $activeKeyId) === 1) {
            return new ConfigurationViolation('AI6_REDACTION_ACTIVE_KEY_ID must not be purely numeric.');
        }

        if (! is_string($activeKeyId) || preg_match(self::KEY_ID_PATTERN, $activeKeyId) !== 1) {
            return new ConfigurationViolation('Configuration key AI6_REDACTION_ACTIVE_KEY_ID is invalid.');
        }

        $configuredKeys = $configuration['keys'] ?? null;

        if ($configuredKeys === null || $configuredKeys === '') {
            return $this->derivedAppKeyring(
                $activeKeyId,
                $configuration['app_key'] ?? null,
                $configuration['environment'] ?? null,
            );
        }

        if (is_string($configuredKeys)) {
            try {
                $configuredKeys = json_decode($configuredKeys, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return new ConfigurationViolation('Configuration key AI6_REDACTION_KEYS must be valid keyring JSON.');
            }
        }

        if (! is_array($configuredKeys) || $configuredKeys === []) {
            return new ConfigurationViolation('Configuration key AI6_REDACTION_KEYS must define at least one key.');
        }

        $keys = [];
        $versions = [];
        foreach ($configuredKeys as $keyId => $entry) {
            $keyId = (string) $keyId;

            if (preg_match(self::PURELY_NUMERIC_KEY_ID_PATTERN, $keyId) === 1) {
                return new ConfigurationViolation('AI6_REDACTION_KEYS key IDs must not be purely numeric.');
            }

            if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1
                || ! is_array($entry)
            ) {
                return new ConfigurationViolation('Configuration key AI6_REDACTION_KEYS contains an invalid key entry.');
            }

            $version = $entry['version'] ?? null;
            if (is_string($version) && preg_match('/\A[1-9][0-9]*\z/D', $version) === 1) {
                $version = (int) $version;
            }

            if (! is_int($version) || $version < 1 || in_array($version, $versions, true)) {
                return new ConfigurationViolation('Configuration key AI6_REDACTION_KEYS requires unique positive versions.');
            }

            $key = $this->decodeKey($entry['key'] ?? null);

            if ($key instanceof ConfigurationViolation) {
                return $key;
            }

            $keys[$keyId] = ['version' => $version, 'key' => $key];
            $versions[] = $version;
        }

        if (! array_key_exists($activeKeyId, $keys)) {
            return new ConfigurationViolation('AI6_REDACTION_ACTIVE_KEY_ID does not identify a configured key.');
        }

        return new RedactionKeyring($activeKeyId, $keys);
    }

    private function derivedAppKeyring(
        string $activeKeyId,
        mixed $appKey,
        mixed $environment,
    ): RedactionKeyring|ConfigurationViolation {
        if (! is_string($environment) || ! in_array($environment, ['local', 'testing'], true)) {
            return new ConfigurationViolation(
                'AI6_REDACTION_KEYS is required unless APP_ENV is local or testing.',
            );
        }

        if ($activeKeyId !== 'app-key-v1') {
            return new ConfigurationViolation('AI6_REDACTION_KEYS is required for the selected active key ID.');
        }

        $keyMaterial = $this->decodeApplicationKey($appKey);

        if ($keyMaterial instanceof ConfigurationViolation) {
            return $keyMaterial;
        }

        $derived = hash_hmac('sha256', self::DERIVATION_DOMAIN, $keyMaterial, true);

        return new RedactionKeyring('app-key-v1', [
            'app-key-v1' => ['version' => 1, 'key' => $derived],
        ], true);
    }

    private function decodeApplicationKey(mixed $appKey): string|ConfigurationViolation
    {
        if (! is_string($appKey) || $appKey === '') {
            return new ConfigurationViolation('APP_KEY is required when AI6_REDACTION_KEYS is not configured.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);

            if (! is_string($decoded) || strlen($decoded) < 32) {
                return new ConfigurationViolation('APP_KEY cannot derive the default redaction key.');
            }

            return $decoded;
        }

        if (strlen($appKey) < 32) {
            return new ConfigurationViolation('APP_KEY cannot derive the default redaction key.');
        }

        return $appKey;
    }

    private function decodeKey(mixed $key): string|ConfigurationViolation
    {
        if (! is_string($key) || ! str_starts_with($key, 'base64:')) {
            return new ConfigurationViolation('Every AI6_REDACTION_KEYS entry requires a base64 key.');
        }

        $decoded = base64_decode(substr($key, 7), true);

        if (! is_string($decoded) || strlen($decoded) < 32) {
            return new ConfigurationViolation('Every AI6_REDACTION_KEYS entry requires at least 32 key bytes.');
        }

        return $decoded;
    }
}
