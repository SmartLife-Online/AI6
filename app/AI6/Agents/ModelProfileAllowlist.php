<?php

namespace App\AI6\Agents;

final readonly class ModelProfileAllowlist
{
    public function __construct(private AgentProfileRegistry $registry) {}

    public function allows(string $profile): bool
    {
        return $this->registry->has($profile);
    }

    /** @return list<string> */
    public function efforts(): array
    {
        return $this->registry->allEfforts();
    }

    public function supportsCombination(string $profile, AgentRole $role, string $model, string $effort): bool
    {
        return $this->registry->supportsCombination($profile, $role, $model, $effort);
    }

    public function supportsRoleEffort(string $profile, AgentRole $role, string $effort): bool
    {
        return $this->registry->supportsRoleEffort($profile, $role, $effort);
    }
}
