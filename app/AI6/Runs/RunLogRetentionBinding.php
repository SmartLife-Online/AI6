<?php

namespace App\AI6\Runs;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The one binding a stored log receives at its persistence (SEC-011): the
 * expiry that applies from the trusted category value at that moment, the
 * size of the redacted text and the central project- and run-bound HMAC
 * fingerprint of that text with key id and version.
 *
 * Both log categories — timeline events and check outputs — bind through this
 * class when their row is created, so the expiry a record carries is the one
 * that applied when it was written: a later change of the configured days
 * neither reveals already expired raw data again nor postpones its deletion.
 * The retention run keeps the binding on the tombstone. A row stored before
 * the binding existed received its expiry and size once, from the retention
 * migration under the trusted configuration of that upgrade — the keyless
 * migration role cannot fingerprint —, and the retention run completes only
 * the fingerprint with the tombstone; the persisted expiry is never re-derived.
 */
final readonly class RunLogRetentionBinding
{
    public function __construct(
        private RetentionPolicy $retention,
        private RedactionFingerprintGenerator $fingerprints,
    ) {}

    /**
     * @return array{retention_expires_at: CarbonImmutable, retention_size_bytes: int, fingerprint_version: int, fingerprint_key_id: string, fingerprint: string}
     */
    public function bind(string $runId, RetentionCategory $category, string $redactedText, CarbonInterface $createdAt): array
    {
        return [
            'retention_expires_at' => $this->retention->limit($category)->expiresAt($createdAt),
            'retention_size_bytes' => strlen($redactedText),
            ...$this->fingerprintBinding($runId, $category, $redactedText),
        ];
    }

    /**
     * The fingerprint part of the binding alone: what a migrated legacy row
     * still lacks when the retention run turns it into a tombstone.
     *
     * @return array{fingerprint_version: int, fingerprint_key_id: string, fingerprint: string}
     */
    public function fingerprintBinding(string $runId, RetentionCategory $category, string $redactedText): array
    {
        $run = Run::query()->find($runId);
        $fingerprint = $this->fingerprints->generate(
            RedactionMatchType::SECRET,
            new RedactionContext(
                $run instanceof Run ? (string) $run->project_id : '',
                $runId,
                $category === RetentionCategory::RUN_LOGS ? 'run-log' : 'check-log',
            ),
            $redactedText,
        );

        return [
            'fingerprint_version' => $fingerprint->version,
            'fingerprint_key_id' => $fingerprint->keyId,
            'fingerprint' => $fingerprint->value,
        ];
    }
}
