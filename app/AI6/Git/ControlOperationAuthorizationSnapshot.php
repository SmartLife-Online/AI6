<?php

namespace App\AI6\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;

final readonly class ControlOperationAuthorizationSnapshot
{
    public function __construct(private CanonicalJson $canonicalJson) {}

    /** @return array<string, bool|int|string|null> */
    public function capture(User $actor, Project $project, string $authorization = 'global_and_project_administrator'): array
    {
        $membership = ProjectMembership::query()
            ->where('user_id', $actor->getKey())
            ->where('project_id', $project->getKey())
            ->first();

        return [
            'actor_id' => (int) $actor->getKey(),
            'active' => (bool) $actor->is_active,
            'global_administrator' => (bool) $actor->is_global_admin,
            'project_id' => (int) $project->getKey(),
            'project_role' => $membership?->role->value,
            'authorization' => $authorization,
        ];
    }

    public function canonical(User $actor, Project $project, string $authorization = 'global_and_project_administrator'): string
    {
        return $this->canonicalJson->normalizeAndEncode($this->capture($actor, $project, $authorization));
    }

    public function matchesCurrent(ControlOperation $operation, User $actor, Project $project): bool
    {
        try {
            $decoded = json_decode($operation->authorization_snapshot_jcs, false, 32, JSON_THROW_ON_ERROR);
            $stored = $this->canonicalJson->normalizeAndEncode($decoded);
        } catch (\JsonException|CanonicalRequestException) {
            return false;
        }
        $authorization = $decoded instanceof \stdClass && is_string($decoded->authorization ?? null)
            ? $decoded->authorization
            : '';

        return hash_equals($stored, $operation->authorization_snapshot_jcs)
            && in_array($authorization, ['global_and_project_administrator', 'approval_auto_start'], true)
            && hash_equals($stored, $this->canonical($actor, $project, $authorization));
    }

    public function authorization(ControlOperation $operation): ?string
    {
        try {
            $decoded = json_decode($operation->authorization_snapshot_jcs, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded instanceof \stdClass && is_string($decoded->authorization ?? null)
            ? $decoded->authorization
            : null;
    }
}
