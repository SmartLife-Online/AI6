<?php

namespace App\AI6\Agents;

final readonly class AgentProfile
{
    /**
     * @param  non-empty-list<string>  $models
     * @param  non-empty-list<string>  $efforts
     * @param  non-empty-list<AgentRole>  $roles
     */
    public function __construct(
        public string $id,
        public string $providerProfileAlias,
        public string $adapterId,
        public array $models,
        public array $efforts,
        public array $roles,
        public CapabilityStatus $capabilityStatus,
        public string $runtimeProfileId,
    ) {}

    public function supports(AgentRole $role, string $model, string $effort): bool
    {
        return in_array($role, $this->roles, true)
            && in_array($model, $this->models, true)
            && in_array($effort, $this->efforts, true);
    }
}
