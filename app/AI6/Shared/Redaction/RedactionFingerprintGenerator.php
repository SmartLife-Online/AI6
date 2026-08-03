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
        $fields = [
            (string) $this->keyring->activeVersion(),
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
            $this->keyring->activeVersion(),
            $this->keyring->activeKeyId(),
            hash_hmac('sha256', $bytes, $this->keyring->activeKey()),
        );
    }
}
