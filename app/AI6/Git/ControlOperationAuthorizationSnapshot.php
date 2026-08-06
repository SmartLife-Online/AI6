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
    public function capture(User $actor, Project $project): array
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
            'authorization' => 'global_and_project_administrator',
        ];
    }

    public function canonical(User $actor, Project $project): string
    {
        return $this->canonicalJson->normalizeAndEncode($this->capture($actor, $project));
    }

    public function matchesCurrent(ControlOperation $operation, User $actor, Project $project): bool
    {
        try {
            $decoded = json_decode($operation->authorization_snapshot_jcs, false, 32, JSON_THROW_ON_ERROR);
            $stored = $this->canonicalJson->normalizeAndEncode($decoded);
        } catch (\JsonException|CanonicalRequestException) {
            return false;
        }

        return hash_equals($stored, $operation->authorization_snapshot_jcs)
            && hash_equals($stored, $this->canonical($actor, $project));
    }
}
