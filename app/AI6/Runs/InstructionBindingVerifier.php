<?php

namespace App\AI6\Runs;

use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotResolver;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;

/** Re-check the run-bound instruction snapshot and sealed runtime profile before every turn. */
final readonly class InstructionBindingVerifier
{
    public function __construct(
        private InstructionCandidateSource $candidates,
        private InstructionSnapshotResolver $resolver,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
    ) {}

    public function driftCode(Run $run): ?string
    {
        $implementation = ($run->agent_profile_snapshot ?? [])['implementation'] ?? null;
        if (! is_array($implementation)
            || ! is_string($provider = $implementation['provider_profile'] ?? null)
            || ! is_string($runtimeProfileId = $implementation['runtime_profile_id'] ?? null)) {
            return 'instruction_binding_missing';
        }

        $bound = ($run->instruction_snapshot ?? [])[$provider] ?? null;
        if (! is_array($bound) || ! is_string($boundHash = $bound['instruction_snapshot_hash'] ?? null)) {
            return 'instruction_binding_missing';
        }

        $project = Project::query()->find($run->project_id);
        if (! $project instanceof Project) {
            return 'instruction_binding_missing';
        }
        $files = ($run->scope_snapshot ?? [])['ticket_files'] ?? [];
        if (! is_array($files)) {
            $files = [];
        }
        $context = new RedactionContext((string) $run->project_id, $run->id, 'instruction-binding');
        $resolved = $this->resolver->resolve(
            $provider,
            $this->candidates->collect($project, $provider, array_values(array_filter($files, 'is_string')), $context),
            $context,
        );
        if (! $this->sameSnapshot($bound, $resolved, $boundHash)) {
            return 'instruction_binding_drift';
        }

        $boundRuntime = ($run->runtime_profile_snapshot ?? [])[$runtimeProfileId] ?? null;
        if (! is_array($boundRuntime) || ! is_string($boundRuntimeHash = $boundRuntime['hash'] ?? null)) {
            return 'runtime_profile_binding_missing';
        }
        try {
            $registered = $this->runtimeProfiles->get($runtimeProfileId);
        } catch (\Throwable) {
            return 'runtime_profile_not_server_bound';
        }
        if (! hash_equals($registered->hash, $boundRuntimeHash)) {
            return 'runtime_profile_drift';
        }

        return null;
    }

    /** @param array<string, mixed> $bound */
    private function sameSnapshot(array $bound, InstructionSnapshot $resolved, string $boundHash): bool
    {
        if (! hash_equals($boundHash, $resolved->hash)) {
            return false;
        }
        $boundEntries = $bound['entries'] ?? null;
        if (! is_array($boundEntries) || count($boundEntries) !== count($resolved->entries)) {
            return false;
        }
        foreach ($resolved->entries as $index => $entry) {
            $boundEntry = $boundEntries[$index] ?? null;
            if (! is_array($boundEntry)
                || ($boundEntry['repository_path'] ?? null) !== $entry->repositoryPath
                || ($boundEntry['blob_sha'] ?? null) !== $entry->blobSha
                || ($boundEntry['priority'] ?? null) !== $entry->priority
                || ($boundEntry['scope'] ?? null) !== $entry->scope
                || ($boundEntry['content_sha256'] ?? null) !== $entry->contentSha256) {
                return false;
            }
        }

        return true;
    }
}
