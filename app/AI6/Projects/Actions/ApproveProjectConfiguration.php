<?php

namespace App\AI6\Projects\Actions;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\Models\ProjectConfigSnapshot;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectConfigurationConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ApproveProjectConfiguration
{
    public function __construct(private ProjectPolicy $policy) {}

    public function handle(
        User $actor,
        Project $project,
        ProjectConfigDraft $draft,
        string $approvalId,
        string $expectedControlCommit,
        string $expectedBlobSha,
        string $expectedConfigHash,
        int $expectedControlGeneration,
    ): ProjectConfigSnapshot {
        if (! Str::isUuid($approvalId)) {
            throw new ProjectConfigurationConflict('approval_id_invalid', 'Die Freigabe-ID ist ungültig.');
        }
        if (! $this->policy->approveConfiguration($actor, $project)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $draft, $approvalId, $expectedControlCommit, $expectedBlobSha, $expectedConfigHash, $expectedControlGeneration): ProjectConfigSnapshot {
            $existing = ProjectConfigSnapshot::query()->where('approval_id', strtolower($approvalId))->first();
            if ($existing instanceof ProjectConfigSnapshot) {
                if ($existing->project_id !== $project->getKey() || $existing->draft_id !== $draft->getKey()
                    || ! hash_equals($existing->control_commit, $expectedControlCommit)
                    || ! hash_equals($existing->blob_sha, $expectedBlobSha)
                    || ! hash_equals($existing->config_hash, $expectedConfigHash)
                    || $existing->control_generation !== $expectedControlGeneration) {
                    throw new ProjectConfigurationConflict('approval_replay_conflict', 'Die Freigabe-ID ist bereits an einen anderen Inhalt gebunden.');
                }

                return $existing;
            }
            $currentProject = Project::query()->findOrFail($project->getKey());
            $currentDraft = ProjectConfigDraft::query()->findOrFail($draft->getKey());
            if (! $this->policy->approveConfiguration($actor, $currentProject)) {
                throw new AuthorizationException;
            }
            if ($currentDraft->project_id !== $currentProject->getKey() || $currentDraft->state !== 'valid'
                || $currentDraft->normalized_config === null || $currentDraft->validation_errors !== []
                || $currentDraft->redaction_matches !== []
                || $currentDraft->blob_sha === null || $currentDraft->config_hash === null
                || $currentProject->control_oid === null
                || ! hash_equals($currentProject->control_oid, $expectedControlCommit)
                || ! hash_equals($currentDraft->control_commit, $expectedControlCommit)
                || ! hash_equals($currentDraft->blob_sha, $expectedBlobSha)
                || ! hash_equals($currentDraft->config_hash, $expectedConfigHash)
                || $currentProject->control_generation !== $expectedControlGeneration
                || $currentDraft->control_generation !== $expectedControlGeneration) {
                throw new ProjectConfigurationConflict('approval_binding_changed', 'Entwurf oder Control-Bindung haben sich vor der Freigabe geändert.');
            }

            return ProjectConfigSnapshot::query()->create([
                'id' => (string) Str::uuid(), 'project_id' => $currentProject->getKey(),
                'approval_id' => strtolower($approvalId), 'draft_id' => $currentDraft->getKey(),
                'control_commit' => $currentDraft->control_commit, 'blob_sha' => $currentDraft->blob_sha,
                'config_hash' => $currentDraft->config_hash, 'normalized_config' => $currentDraft->normalized_config,
                'control_generation' => $currentDraft->control_generation, 'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ]);
        });
    }
}
