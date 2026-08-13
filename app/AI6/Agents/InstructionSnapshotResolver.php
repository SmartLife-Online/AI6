<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Normalizer;

final readonly class InstructionSnapshotResolver
{
    public function __construct(
        private InstructionProfileRegistry $profiles,
        private AgentInputLimits $limits,
        private Redactor $redactor,
        private CanonicalJson $canonicalJson,
    ) {}

    /** @param list<InstructionCandidate> $candidates */
    public function resolve(string $providerProfileAlias, array $candidates, RedactionContext $context): InstructionSnapshot
    {
        $profile = $this->profiles->get($providerProfileAlias);
        if (count($candidates) > $this->limits->maxInstructionFiles) {
            throw new InstructionResolutionException(InstructionResolutionError::FILE_COUNT_EXCEEDED);
        }

        $entriesByPath = [];
        $totalBytes = 0;
        foreach ($candidates as $candidate) {
            $this->assertCandidateBoundary($candidate);
            $discovery = $profile->discoveries[$candidate->discoveryName]
                ?? throw new InstructionResolutionException(InstructionResolutionError::DISCOVERY_UNKNOWN);
            $path = $this->normalizePath($candidate->repositoryPath);
            if (isset($entriesByPath[$path])) {
                throw new InstructionResolutionException(InstructionResolutionError::PATH_DUPLICATE);
            }
            if (preg_match('/\A[0-9a-f]{40}\z/D', $candidate->blobSha) !== 1) {
                throw new InstructionResolutionException(InstructionResolutionError::BLOB_SHA_INVALID);
            }

            $bytes = strlen($candidate->content);
            if ($bytes > $this->limits->maxInstructionFileBytes) {
                throw new InstructionResolutionException(InstructionResolutionError::FILE_BYTES_EXCEEDED);
            }
            $totalBytes += $bytes;
            if ($totalBytes > $this->limits->maxInstructionTotalBytes) {
                throw new InstructionResolutionException(InstructionResolutionError::TOTAL_BYTES_EXCEEDED);
            }

            try {
                $redacted = $this->redactor->redact($candidate->content, $context);
            } catch (InvalidRedactionInputException) {
                throw new InstructionResolutionException(InstructionResolutionError::UTF8_INVALID);
            }
            $imports = [];
            foreach ($candidate->imports as $import) {
                $imports[] = $this->normalizePath($import);
            }
            if (count(array_unique($imports)) !== count($imports)) {
                throw new InstructionResolutionException(InstructionResolutionError::PATH_DUPLICATE);
            }
            sort($imports, SORT_STRING);
            $entriesByPath[$path] = new InstructionSnapshotEntry(
                $candidate->discoveryName,
                $discovery->scope,
                $discovery->priority,
                $path,
                $candidate->blobSha,
                $redacted->text,
                $imports,
            );
        }

        $this->assertImportGraph($entriesByPath);
        $entries = array_values($entriesByPath);
        usort($entries, static fn (InstructionSnapshotEntry $left, InstructionSnapshotEntry $right): int => $left->priority <=> $right->priority ?: strcmp($left->repositoryPath, $right->repositoryPath));
        $input = [
            'provider_profile_alias' => $providerProfileAlias,
            'entries' => array_map(static fn (InstructionSnapshotEntry $entry): array => $entry->jsonSerialize(), $entries),
        ];
        $hash = hash('sha256', "AI6-INSTRUCTION-SNAPSHOT-V1\0".$this->canonicalJson->normalizeAndEncode($input));

        return new InstructionSnapshot($providerProfileAlias, $entries, $hash);
    }

    private function assertCandidateBoundary(InstructionCandidate $candidate): void
    {
        if ($candidate->origin === InstructionCandidateOrigin::HOST) {
            throw new InstructionResolutionException(InstructionResolutionError::HOST_SOURCE_FORBIDDEN);
        }
        if ($candidate->origin === InstructionCandidateOrigin::PARENT) {
            throw new InstructionResolutionException(InstructionResolutionError::PARENT_SOURCE_FORBIDDEN);
        }
        if (! $candidate->exists) {
            throw new InstructionResolutionException(InstructionResolutionError::FILE_MISSING);
        }
        if ($candidate->fileType !== InstructionFileType::REGULAR) {
            throw new InstructionResolutionException(InstructionResolutionError::SYMLINK_FORBIDDEN);
        }
    }

    private function normalizePath(string $path): string
    {
        if (preg_match('//u', $path) !== 1) {
            throw new InstructionResolutionException(InstructionResolutionError::PATH_INVALID);
        }
        $normalized = Normalizer::normalize($path, Normalizer::FORM_C);
        $segments = is_string($normalized) ? explode('/', $normalized) : [];
        if (! is_string($normalized)
            || $normalized === ''
            || str_contains($normalized, '\\')
            || str_starts_with($normalized, '/')
            || preg_match('/\A[A-Za-z]:/', $normalized) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InstructionResolutionException(InstructionResolutionError::PATH_INVALID);
        }

        return $normalized;
    }

    /** @param array<string, InstructionSnapshotEntry> $entries */
    private function assertImportGraph(array $entries): void
    {
        foreach ($entries as $entry) {
            foreach ($entry->imports as $import) {
                if (! isset($entries[$import])) {
                    throw new InstructionResolutionException(InstructionResolutionError::IMPORT_TARGET_MISSING);
                }
            }
        }

        $state = [];
        $depths = [];
        $visit = function (string $path) use (&$visit, &$state, &$depths, $entries): int {
            if (($state[$path] ?? null) === 'visiting') {
                throw new InstructionResolutionException(InstructionResolutionError::IMPORT_CYCLE);
            }
            if (($state[$path] ?? null) === 'done') {
                return $depths[$path];
            }
            $state[$path] = 'visiting';
            $depth = 1;
            foreach ($entries[$path]->imports as $import) {
                $depth = max($depth, 1 + $visit($import));
            }
            $state[$path] = 'done';
            $depths[$path] = $depth;

            return $depth;
        };

        foreach (array_keys($entries) as $path) {
            if ($visit($path) > $this->limits->maxInstructionImportDepth) {
                throw new InstructionResolutionException(InstructionResolutionError::IMPORT_DEPTH_EXCEEDED);
            }
        }
    }
}
