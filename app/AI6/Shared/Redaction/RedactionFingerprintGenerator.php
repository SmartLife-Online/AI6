<?php

namespace App\AI6\Shared\Redaction;

use App\AI6\Shared\Security\CanonicalByteFrame;

final readonly class RedactionFingerprintGenerator
{
    public const DOMAIN = 'AI6-REDACTION-FINGERPRINT-V1';

    public const FIELD_ORDER = [
        'fingerprint_version',
        'match_type',
        'context_identifier',
        'project_id',
        'run_id_if_present',
        'removed_value',
    ];

    public function __construct(private RedactionKeyring $keyring) {}

    public function generate(
        RedactionMatchType $type,
        RedactionContext $context,
        string $removedValue,
    ): RedactionFingerprint {
        return $this->generateUnderKey($this->keyring->activeKeyId(), $type, $context, $removedValue);
    }

    /**
     * The same fingerprint under a named key of the ring. A consumer that has
     * to recognize a value bound under a retired key recomputes it here with
     * that key's own version; new fingerprints always use generate().
     */
    public function generateUnderKey(
        string $keyId,
        RedactionMatchType $type,
        RedactionContext $context,
        string $removedValue,
    ): RedactionFingerprint {
        $version = $this->keyring->versionOf($keyId);
        $fields = [
            (string) $version,
            $type->value,
            $context->identifier,
            $context->projectId,
        ];

        if ($context->runId !== null) {
            $fields[] = $context->runId;
        }

        $fields[] = $removedValue;
        $bytes = CanonicalByteFrame::encode(self::DOMAIN, $fields);

        return new RedactionFingerprint(
            $version,
            $keyId,
            hash_hmac('sha256', $bytes, $this->keyring->keyOf($keyId)),
        );
    }

    public function hasKey(string $keyId): bool
    {
        return $this->keyring->has($keyId);
    }

    /**
     * Every key id of the ring, active and retired, for consumers that must
     * recognize a value bound under any of them.
     *
     * @return list<string>
     */
    public function keyIds(): array
    {
        return $this->keyring->keyIds();
    }
}
