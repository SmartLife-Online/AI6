<?php

namespace App\AI6\Runs;

use JsonSerializable;

final readonly class ApprovalSnapshot implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $instructions
     * @param  array<string, mixed>  $runtimeProfiles
     * @param  array<string, mixed>  $agentProfiles
     */
    public function __construct(
        public array $config,
        public string $configHash,
        public array $scope,
        public string $scopeHash,
        public array $prompt,
        public string $promptHash,
        public array $instructions,
        public string $instructionHash,
        public array $runtimeProfiles,
        public string $runtimeProfileHash,
        public array $agentProfiles,
        public string $agentProfileHash,
        public string $securityPolicyHash,
        public string $aggregateHash,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'config' => $this->config,
            'config_hash' => $this->configHash,
            'scope' => $this->scope,
            'scope_hash' => $this->scopeHash,
            'prompt' => $this->prompt,
            'prompt_hash' => $this->promptHash,
            'instructions' => $this->instructions,
            'instruction_hash' => $this->instructionHash,
            'runtime_profiles' => $this->runtimeProfiles,
            'runtime_profile_hash' => $this->runtimeProfileHash,
            'agent_profiles' => $this->agentProfiles,
            'agent_profile_hash' => $this->agentProfileHash,
            'security_policy_hash' => $this->securityPolicyHash,
            'aggregate_hash' => $this->aggregateHash,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        foreach (['config', 'scope', 'prompt', 'instructions', 'runtime_profiles', 'agent_profiles'] as $field) {
            if (! is_array($value[$field] ?? null)) {
                throw new \InvalidArgumentException('Der persistierte Approval-Snapshot ist unvollständig.');
            }
        }
        foreach (['config_hash', 'scope_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash', 'agent_profile_hash', 'security_policy_hash', 'aggregate_hash'] as $field) {
            if (! is_string($value[$field] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $value[$field]) !== 1) {
                throw new \InvalidArgumentException('Der persistierte Approval-Snapshot ist unvollständig.');
            }
        }

        return new self(
            $value['config'],
            $value['config_hash'],
            $value['scope'],
            $value['scope_hash'],
            $value['prompt'],
            $value['prompt_hash'],
            $value['instructions'],
            $value['instruction_hash'],
            $value['runtime_profiles'],
            $value['runtime_profile_hash'],
            $value['agent_profiles'],
            $value['agent_profile_hash'],
            $value['security_policy_hash'],
            $value['aggregate_hash'],
        );
    }
}
