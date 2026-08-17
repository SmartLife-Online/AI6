<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;

final readonly class AgentResultValidator
{
    public function __construct(
        private RestrictedJsonDecoder $decoder,
        private Redactor $redactor,
        private AgentInputLimits $inputLimits,
    ) {}

    public function validate(string $bytes, AgentResultContext $context, RedactionContext $redactionContext): AgentResult
    {
        $document = $this->decoder->decode($bytes, $redactionContext);
        $allowedKeys = [
            'schema_version',
            'status',
            'summary',
            'prompt_snapshot_hash',
            'instruction_snapshot_hash',
            'provider_runtime_profile_hash',
            'human_request',
        ];
        if ($context->role === AgentRole::IMPLEMENTATION) {
            $allowedKeys[] = 'instruction_patch';
        } else {
            $allowedKeys[] = 'findings';
            $allowedKeys[] = 'criterion_coverage';
        }
        $this->assertKeys($document, $allowedKeys, [
            'schema_version',
            'status',
            'summary',
        ]);

        $schemaVersion = $this->string($document, 'schema_version');
        $expectedSchema = $context->role === AgentRole::IMPLEMENTATION ? 'ai6.agent.v1' : 'ai6.quality-review.v1';
        if ($schemaVersion !== $expectedSchema) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        $status = AgentResultStatus::tryFrom($this->string($document, 'status'));
        if ($status === null || ! in_array($status, AgentResultStatus::allowedFor($context->role), true)) {
            throw new AgentResultValidationException(AgentResultValidationError::STATUS);
        }
        $summary = $this->string($document, 'summary');
        if ($summary === '') {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        $this->assertBindings($document, $context);

        $humanRequest = array_key_exists('human_request', $document) ? $this->humanRequest($document['human_request'], $context) : null;
        $findings = array_key_exists('findings', $document) ? $this->findings($document['findings'], $context) : [];
        $coverage = array_key_exists('criterion_coverage', $document) ? $this->coverage($document['criterion_coverage'], $context) : [];
        $patch = array_key_exists('instruction_patch', $document) ? $this->instructionPatch($document['instruction_patch'], $context, $redactionContext) : null;

        if ($status === AgentResultStatus::NO_CHANGE_REQUIRED && $context->actualDiff !== '') {
            throw new AgentResultValidationException(AgentResultValidationError::NO_CHANGE_DIFF);
        }
        if (in_array($context->role, [AgentRole::QUALITY_REVIEW, AgentRole::SECURITY_REVIEW], true)
            && array_diff($context->criterionRefs, array_map(static fn (CriterionCoverageEntry $entry): string => $entry->criterionId, $coverage)) !== []) {
            throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
        }
        if ($status === AgentResultStatus::FINDINGS_TO_FIX && $findings === []) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        if ($status === AgentResultStatus::SECURITY_FINDINGS && $findings === []) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        if ($patch !== null && $context->role !== AgentRole::IMPLEMENTATION) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }

        return new AgentResult($schemaVersion, $status, $summary, $humanRequest, $findings, $coverage, $patch);
    }

    /** @param array<string, mixed> $document */
    private function assertBindings(array $document, AgentResultContext $context): void
    {
        foreach (['prompt_snapshot_hash', 'instruction_snapshot_hash', 'provider_runtime_profile_hash'] as $binding) {
            if (! array_key_exists($binding, $document) || ! is_string($document[$binding])) {
                throw new AgentResultValidationException(AgentResultValidationError::BINDING);
            }
        }
        if ($this->string($document, 'prompt_snapshot_hash') !== $context->promptSnapshot->hash
            || $this->string($document, 'instruction_snapshot_hash') !== $context->instructionSnapshot->hash
            || $this->string($document, 'provider_runtime_profile_hash') !== $context->runtimeProfile->hash) {
            throw new AgentResultValidationException(AgentResultValidationError::BINDING);
        }
    }

    private function humanRequest(mixed $value, AgentResultContext $context): ?HumanRequestProposal
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        $this->assertKeys($value, ['kind', 'title', 'message', 'why_needed', 'response_mode', 'options', 'recommended_option', 'affected_paths', 'criterion_refs']);
        $options = $this->options($value['options'] ?? null);
        $affectedPaths = $this->paths($value['affected_paths'] ?? null);
        $criterionRefs = $this->criterionRefs($value['criterion_refs'] ?? null, $context);
        $recommended = $value['recommended_option'] ?? null;
        $optionKeys = array_map(static fn (HumanRequestOption $option): string => $option->key, $options);
        if ($recommended !== null && (! is_string($recommended) || ! in_array($recommended, $optionKeys, true))) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }

        return new HumanRequestProposal(
            $this->string($value, 'kind'),
            $this->string($value, 'title'),
            $this->string($value, 'message'),
            $this->string($value, 'why_needed'),
            $this->string($value, 'response_mode'),
            $options,
            $recommended,
            $affectedPaths,
            $criterionRefs,
        );
    }

    /** @return list<AgentFinding> */
    private function findings(mixed $value, AgentResultContext $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        $findings = [];
        $identifiers = [];
        foreach ($value as $finding) {
            if (! is_array($finding) || array_is_list($finding)) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
            $this->assertKeys($finding, ['local_id', 'severity', 'disposition', 'category', 'file', 'line', 'title', 'evidence', 'expected_result', 'criterion_refs']);
            $localId = $this->string($finding, 'local_id');
            if ($localId === '' || isset($identifiers[$localId])) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
            $identifiers[$localId] = true;
            $line = $finding['line'] ?? null;
            if (! is_int($line) || $line < 1) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
            $file = $this->path($this->string($finding, 'file'));
            $findings[] = new AgentFinding(
                $localId,
                $this->nonEmpty($finding, 'severity'),
                $this->nonEmpty($finding, 'disposition'),
                $this->nonEmpty($finding, 'category'),
                $file,
                $line,
                $this->nonEmpty($finding, 'title'),
                $this->nonEmpty($finding, 'evidence'),
                $this->nonEmpty($finding, 'expected_result'),
                $this->criterionRefs($finding['criterion_refs'] ?? null, $context),
            );
        }

        return $findings;
    }

    /** @return list<CriterionCoverageEntry> */
    private function coverage(mixed $value, AgentResultContext $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
        }
        $coverage = [];
        $identifiers = [];
        foreach ($value as $entry) {
            if (! is_array($entry) || array_is_list($entry)) {
                throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
            }
            $this->assertKeys($entry, ['criterion_id', 'status', 'evidence']);
            $criterionId = $this->nonEmpty($entry, 'criterion_id');
            if (isset($identifiers[$criterionId]) || ! in_array($criterionId, $context->criterionRefs, true)) {
                throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
            }
            $identifiers[$criterionId] = true;
            $coverage[] = new CriterionCoverageEntry($criterionId, $this->nonEmpty($entry, 'status'), $this->nonEmpty($entry, 'evidence'));
        }
        if (count($coverage) !== count($context->criterionRefs)) {
            throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
        }

        return $coverage;
    }

    private function instructionPatch(mixed $value, AgentResultContext $context, RedactionContext $redactionContext): ?InstructionPatch
    {
        if ($value === null) {
            return null;
        }
        if (! $context->instructionUpdate || ! is_array($value) || array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $this->assertKeys($value, ['schema_version', 'path', 'expected_blob_sha', 'format', 'content_base64', 'content_length', 'content_sha256']);
        if ($this->string($value, 'schema_version') !== 'ai6.instruction-patch.v1'
            || $this->string($value, 'format') !== 'utf8_file_replacement_v1') {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $path = $this->path($this->string($value, 'path'));
        if (! in_array($path, $context->initialScope, true)) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $expectedBlob = $value['expected_blob_sha'] ?? null;
        if ($expectedBlob !== null && (! is_string($expectedBlob) || preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D', $expectedBlob) !== 1)) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        if (! array_key_exists($path, $context->expectedInstructionBlobs) || $context->expectedInstructionBlobs[$path] !== $expectedBlob) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $encoded = $this->string($value, 'content_base64');
        $content = base64_decode($encoded, true);
        if (! is_string($content) || base64_encode($content) !== $encoded || str_starts_with($content, "\xEF\xBB\xBF")) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        try {
            $this->redactor->assertValidInput($content);
            if ($this->redactor->redact($content, $redactionContext)->text !== $content) {
                throw new AgentResultValidationException(AgentResultValidationError::REDACTED_INSTRUCTION_PATCH);
            }
        } catch (InvalidRedactionInputException) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $length = $value['content_length'] ?? null;
        $hash = $value['content_sha256'] ?? null;
        if (! is_int($length) || $length !== strlen($content) || ! is_string($hash)
            || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1 || ! hash_equals($hash, hash('sha256', $content))) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        if ($length > $this->inputLimits->maxInstructionFileBytes) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }
        $totalBytes = array_sum(array_map(
            static fn (InstructionSnapshotEntry $entry): int => $entry->repositoryPath === $path ? 0 : strlen($entry->effectiveContent),
            $context->instructionSnapshot->entries,
        )) + $length;
        if ($totalBytes > $this->inputLimits->maxInstructionTotalBytes) {
            throw new AgentResultValidationException(AgentResultValidationError::INSTRUCTION_PATCH);
        }

        return new InstructionPatch($path, $expectedBlob, $this->nonEmpty($value, 'format'), $content, $length, $hash);
    }

    /** @param array<string, mixed> $document */
    private function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (! is_string($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }

        return $value;
    }

    /** @param array<string, mixed> $document */
    private function nonEmpty(array $document, string $key): string
    {
        $value = $this->string($document, $key);
        if ($value === '') {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
        }

        return $value;
    }

    /** @return list<HumanRequestOption> */
    private function options(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }
        $options = [];
        $keys = [];
        foreach ($value as $option) {
            if (! is_array($option) || array_is_list($option)) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
            $this->assertKeys($option, ['key', 'label']);
            $key = $this->nonEmpty($option, 'key');
            if (isset($keys[$key])) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
            $keys[$key] = true;
            $options[] = new HumanRequestOption($key, $this->nonEmpty($option, 'label'));
        }

        return $options;
    }

    /** @return list<string> */
    private function criterionRefs(mixed $value, AgentResultContext $context): array
    {
        $references = $this->strings($value);
        if (count($references) !== count(array_unique($references))) {
            throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
        }
        foreach ($references as $reference) {
            if (! in_array($reference, $context->criterionRefs, true)) {
                throw new AgentResultValidationException(AgentResultValidationError::CRITERION_REFERENCE);
            }
        }

        return $references;
    }

    /** @return list<string> */
    private function paths(mixed $value): array
    {
        $paths = $this->strings($value);

        return array_map($this->path(...), $paths);
    }

    private function path(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, "\0") || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $allowed
     * @param  null|list<string>  $required
     */
    private function assertKeys(array $document, array $allowed, ?array $required = null): void
    {
        foreach (array_keys($document) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
        }
        foreach ($required ?? $allowed as $requiredKey) {
            if (! array_key_exists($requiredKey, $document)) {
                throw new AgentResultValidationException(AgentResultValidationError::SCHEMA);
            }
        }
    }
}
