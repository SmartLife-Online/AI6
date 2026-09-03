<?php

namespace App\AI6\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprint;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

final readonly class RunArtifactStore
{
    public function __construct(
        private RunArtifactRoot $root,
        private Redactor $redactor,
        private CanonicalJson $json,
        private RetentionPolicy $retention,
        private RedactionFingerprintGenerator $fingerprints,
    ) {}

    /**
     * Redact string values before encoding so a match cannot consume JSON
     * syntax and turn a structured artifact into invalid JSON.
     *
     * Encoding and persisting stay separate calls because every consumer has to
     * measure the encoded bytes against the approved artifact limit before
     * anything reaches the store.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encodeCanonicalJson(array $payload, RedactionContext $context): string
    {
        return $this->json->normalizeAndEncode($this->redactJsonValue($payload, $context));
    }

    /** @param array<string, mixed> $metadata */
    public function persistEncoded(Run $run, RunArtifactKind $kind, string $bytes, array $metadata, RedactionContext $context): RunArtifact
    {
        return $this->persist($run, $kind, $bytes, $metadata, $context);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function store(Run $run, RunArtifactKind $kind, string $bytes, array $metadata, RedactionContext $context): RunArtifact
    {
        $this->redactor->assertValidInput($bytes);
        $redacted = $this->redactor->redact($bytes, $context);

        return $this->persist($run, $kind, $redacted->text, $metadata, $context);
    }

    /** Remove an unpublished transient artifact after a fenced invocation. */
    public function discard(Run $run, RunArtifact $artifact): void
    {
        if ($artifact->run_id !== $run->id) {
            throw new ImplementationImportException('artifact_run_mismatch', 'The artifact does not belong to the run.');
        }
        if ($artifact->kind !== RunArtifactKind::CONTEXT_PACKAGE) {
            throw new ImplementationImportException('artifact_kind_not_discardable', 'Only a transient run artifact may be discarded.');
        }
        $path = $this->storagePath($artifact);
        if ($path !== null && is_file($path) && ! @unlink($path)) {
            throw new ImplementationImportException('artifact_delete_failed', 'The transient run artifact could not be removed.');
        }
        $artifact->delete();
    }

    /**
     * The stored redacted bytes of an artifact, or null once its retention
     * ended — from expiry on, before and after the retention run removed the
     * storage object — or they are otherwise unavailable. A tombstone never
     * yields bytes. This is the one predicate every output path consumes.
     */
    public function bytes(RunArtifact $artifact): ?string
    {
        if ($artifact->isDeleted() || $artifact->isExpired(Date::now())) {
            return null;
        }
        $path = $this->storagePath($artifact);
        if ($path === null || ! is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = file_get_contents($path);

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Delete the raw bytes of an artifact and turn its record into the
     * tombstone (SEC-011).
     *
     * A record without a fingerprint — a legacy row stored before the
     * fingerprint binding — receives it from the bytes it is about to lose,
     * and that binding is persisted before the storage object is touched: a
     * crash between the file removal and the tombstone update must resume
     * into a tombstone that still recognizes a redelivered copy, never into
     * a fingerprint-less record. The storage object is removed next; only
     * then does the record lose its raw reference and unkeyed digest.
     * Repeating the call on a tombstone changes nothing and reports false.
     */
    public function purge(RunArtifact $artifact, CarbonInterface $now): bool
    {
        if ($artifact->isDeleted()) {
            return false;
        }
        // A tombstone asserts that the storage object is gone. Without the
        // trusted root in reach — an unmounted volume, another role — the
        // assertion would be false, so the deletion is refused by name.
        if (! is_dir($this->root->path) || is_link($this->root->path)) {
            throw new ImplementationImportException('artifact_root_unavailable', 'The trusted run-artifact root is unavailable; the artifact cannot be purged.');
        }
        $path = $this->storagePath($artifact);
        if ($artifact->fingerprint === null && $path !== null && is_file($path) && ! is_link($path)) {
            $bytes = file_get_contents($path);
            if (is_string($bytes) && preg_match('//u', $bytes) === 1) {
                $fingerprint = $this->fingerprint($artifact->run_id, $this->projectId($artifact), $artifact->kind, $bytes);
                $bound = RunArtifact::query()->whereKey($artifact->getKey())
                    ->where('retention_state', RunArtifactRetentionState::STORED->value)
                    ->whereNull('fingerprint')
                    ->update([
                        'fingerprint_version' => $fingerprint->version,
                        'fingerprint_key_id' => $fingerprint->keyId,
                        'fingerprint' => $fingerprint->value,
                    ]);
                if ($bound !== 1) {
                    throw new ImplementationImportException('artifact_fingerprint_not_bound', 'The legacy run artifact could not be bound to its fingerprint before its removal.');
                }
                $artifact->refresh();
            }
        }
        if ($path !== null && (is_file($path) || is_link($path))) {
            if (! @unlink($path) || is_file($path) || is_link($path)) {
                throw new ImplementationImportException('artifact_delete_failed', 'The expired run artifact could not be removed from storage.');
            }
            $directory = dirname($path);
            if (is_dir($directory) && count(scandir($directory) ?: []) === 2) {
                @rmdir($directory);
            }
        } elseif ($path !== null && file_exists($path)) {
            // Something that is not the stored file sits at the path; a
            // tombstone would assert a removal that did not happen.
            throw new ImplementationImportException('artifact_delete_failed', 'The expired run artifact path holds an unexpected object.');
        }

        $updated = RunArtifact::query()->whereKey($artifact->getKey())
            ->where('retention_state', RunArtifactRetentionState::STORED->value)
            ->update([
                'digest' => null,
                'storage_reference' => null,
                'retention_state' => RunArtifactRetentionState::DELETED,
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        $artifact->refresh();

        return $updated === 1;
    }

    /** @param array<string, mixed> $metadata */
    private function persist(Run $run, RunArtifactKind $kind, string $payload, array $metadata, RedactionContext $context): RunArtifact
    {
        $limit = $this->retention->artifactLimit($kind);
        if ($limit->exceeds(strlen($payload))) {
            throw new ImplementationImportException('artifact_retention_size_exceeded', 'The run artifact exceeds the trusted size limit of its retention category.');
        }

        $digest = hash('sha256', $payload);
        $existing = RunArtifact::query()
            ->where('run_id', $run->getKey())
            ->where('kind', $kind->value)
            ->where('digest', $digest)
            ->first();
        if ($existing instanceof RunArtifact) {
            return $existing;
        }

        // Raw content that retention already removed is never stored a second
        // time: the tombstone keeps only the keyed fingerprint, and exactly
        // that fingerprint identifies a redelivered copy of the removed bytes.
        // Limit bindings are decisions, not raw output, and may legitimately
        // recur with identical bytes in a long-running run.
        $fingerprint = $this->fingerprint($run->id, (string) $run->project_id, $kind, $payload);
        if (! in_array($kind, [RunArtifactKind::LIMIT_PENDING, RunArtifactKind::LIMIT_GRANT], true)) {
            $this->assertNotRemoved($run, $kind, $payload);
        }

        $directory = rtrim($this->root->path, '/\\').DIRECTORY_SEPARATOR.$run->id;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ImplementationImportException('artifact_root_unavailable', 'The trusted run-artifact root is unavailable.');
        }
        $filename = $kind->value.'-'.$digest.'.txt';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        if (file_put_contents($path, $payload, LOCK_EX) !== strlen($payload)) {
            throw new ImplementationImportException('artifact_write_failed', 'The run artifact could not be stored.');
        }
        @chmod($path, 0600);

        $safeMetadata = [];
        foreach ($metadata as $key => $value) {
            $safeMetadata[$key] = is_string($value)
                ? $this->redactor->redact($value, $context)->text
                : $value;
        }

        $sequence = (int) RunArtifact::query()->where('run_id', $run->getKey())->max('sequence') + 1;
        $now = Date::now();

        try {
            return RunArtifact::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $run->id,
                'kind' => $kind,
                'redacted_metadata' => $safeMetadata,
                'digest' => $digest,
                'size_bytes' => strlen($payload),
                'sequence' => $sequence,
                'storage_reference' => $run->id.'/'.$filename,
                'expires_at' => $limit->expiresAt($now),
                'retention_state' => RunArtifactRetentionState::STORED,
                'fingerprint_version' => $fingerprint->version,
                'fingerprint_key_id' => $fingerprint->keyId,
                'fingerprint' => $fingerprint->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            $created = RunArtifact::query()
                ->where('run_id', $run->getKey())
                ->where('kind', $kind->value)
                ->where('digest', $digest)
                ->first();
            if (! $created instanceof RunArtifact) {
                throw new ImplementationImportException('artifact_write_failed', 'The run artifact could not be stored.');
            }

            return $created;
        }
    }

    /**
     * The resurrection lock of SEC-011: a tombstone of this run and kind whose
     * fingerprint matches the bytes refuses the store by name.
     *
     * Each tombstone is bound to the key id and version it was written under,
     * so the comparison recomputes the fingerprint under exactly that key of
     * the versioned ring — a rotation of the active key does not disarm the
     * lock. A tombstone that cannot be compared — no fingerprint binding on a
     * legacy row whose bytes were already gone, a key the ring no longer
     * holds, a version the ring binds differently — makes the lock
     * unverifiable; storing past it would forge its input, so that case is
     * refused by its own name until the binding is verifiable again.
     */
    private function assertNotRemoved(Run $run, RunArtifactKind $kind, string $payload): void
    {
        $tombstones = RunArtifact::query()
            ->where('run_id', $run->getKey())
            ->where('kind', $kind->value)
            ->where('retention_state', RunArtifactRetentionState::DELETED->value)
            ->get(['fingerprint_key_id', 'fingerprint_version', 'fingerprint']);
        if ($tombstones->isEmpty()) {
            return;
        }
        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-artifact:'.$kind->value);
        $recomputed = [];
        foreach ($tombstones as $tombstone) {
            $keyId = $tombstone->fingerprint_key_id;
            if (! is_string($keyId) || $tombstone->fingerprint === null || $tombstone->fingerprint_version === null
                || ! $this->fingerprints->hasKey($keyId)) {
                throw new ImplementationImportException('artifact_retention_lock_unverifiable', 'A retention tombstone of this run has no verifiable fingerprint binding; the artifact is not stored.');
            }
            $recomputed[$keyId] ??= $this->fingerprints->generateUnderKey($keyId, RedactionMatchType::SECRET, $context, $payload);
            if ($recomputed[$keyId]->version !== $tombstone->fingerprint_version) {
                throw new ImplementationImportException('artifact_retention_lock_unverifiable', 'A retention tombstone of this run is bound to a key version the ring does not hold; the artifact is not stored.');
            }
            if (hash_equals($recomputed[$keyId]->value, (string) $tombstone->fingerprint)) {
                throw new ImplementationImportException('artifact_retention_expired', 'The run artifact content was removed by retention and is not stored again.');
            }
        }
    }

    /**
     * The tombstone fingerprint of the stored bytes: the central versioned,
     * domain-separated, project- and run-bound HMAC. The artifact bytes are the
     * removed value; the context identifier separates artifact fingerprints
     * from the fingerprints of redacted text matches.
     */
    private function fingerprint(string $runId, string $projectId, RunArtifactKind $kind, string $payload): RedactionFingerprint
    {
        return $this->fingerprints->generate(
            RedactionMatchType::SECRET,
            new RedactionContext($projectId, $runId, 'run-artifact:'.$kind->value),
            $payload,
        );
    }

    private function projectId(RunArtifact $artifact): string
    {
        $run = Run::query()->find($artifact->run_id);
        if (! $run instanceof Run) {
            throw new ImplementationImportException('artifact_run_mismatch', 'The artifact does not belong to a run.');
        }

        return (string) $run->project_id;
    }

    /** The storage path under the trusted root, or null for a tombstone. */
    private function storagePath(RunArtifact $artifact): ?string
    {
        if ($artifact->storage_reference === null) {
            return null;
        }
        $directory = rtrim($this->root->path, '/\\').DIRECTORY_SEPARATOR.$artifact->run_id;

        return $directory.DIRECTORY_SEPARATOR.basename($artifact->storage_reference);
    }

    private function redactJsonValue(mixed $value, RedactionContext $context): mixed
    {
        if (is_string($value)) {
            return $this->redactor->redact($value, $context)->text;
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->redactJsonValue($nested, $context);
        }

        return $value;
    }
}
