<?php

namespace App\AI6\Runs;

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
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function store(Run $run, RunArtifactKind $kind, string $bytes, array $metadata, RedactionContext $context): RunArtifact
    {
        $this->redactor->assertValidInput($bytes);
        $redacted = $this->redactor->redact($bytes, $context);
        $payload = $redacted->text;
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
}
