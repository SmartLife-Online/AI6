<?php

namespace App\AI6\Runs;

use App\AI6\Git\CanonicalJson;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class RunArtifactStore
{
    public function __construct(
        private RunArtifactRoot $root,
        private Redactor $redactor,
        private CanonicalJson $json,
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
        $directory = rtrim($this->root->path, '/\\').DIRECTORY_SEPARATOR.$run->id;
        $filename = basename($artifact->storage_reference);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        if (is_file($path) && ! @unlink($path)) {
            throw new ImplementationImportException('artifact_delete_failed', 'The transient run artifact could not be removed.');
        }
        $artifact->delete();
    }

    /** @param array<string, mixed> $metadata */
    private function persist(Run $run, RunArtifactKind $kind, string $payload, array $metadata, RedactionContext $context): RunArtifact
    {
        $digest = hash('sha256', $payload);
        $existing = RunArtifact::query()
            ->where('run_id', $run->getKey())
            ->where('kind', $kind->value)
            ->where('digest', $digest)
            ->first();
        if ($existing instanceof RunArtifact) {
            return $existing;
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
                'expires_at' => now()->addDays(30),
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
