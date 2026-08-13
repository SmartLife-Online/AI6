<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictEnumParser;

final readonly class AgentProfileRegistry
{
    private const PROVIDER_PROFILE_ALIASES = [
        'codex_cli',
        'grok_cli',
        'github_copilot_cli',
        'fake',
    ];

    /** @var array<string, AgentProfile> */
    private array $profiles;

    /** @param array<string, AgentProfile> $profiles */
    private function __construct(array $profiles)
    {
        ksort($profiles, SORT_STRING);
        $this->profiles = $profiles;
    }

    public static function fromConfiguredValues(StrictEnumParser $enumParser): self
    {
        $configured = config('ai6.agent_profiles');

        return self::fromArray(is_array($configured) ? $configured : [], $enumParser);
    }

    /** @param array<array-key, mixed> $configured */
    public static function fromArray(array $configured, StrictEnumParser $enumParser): self
    {
        if ($configured === [] || array_is_list($configured)) {
            throw new ConfigurationException('Configuration key ai6.agent_profiles must be a non-empty mapping.');
        }

        $profiles = [];
        $aliases = [];
        foreach ($configured as $id => $value) {
            $key = 'ai6.agent_profiles.'.(is_string($id) ? $id : 'invalid');
            if (! is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $id) !== 1) {
                throw new ConfigurationException('Configuration key ai6.agent_profiles contains an invalid profile identifier.');
            }
            if (! is_array($value) || array_is_list($value) || array_keys($value) !== [
                'provider_profile', 'adapter', 'models', 'efforts', 'roles', 'capability_status', 'runtime_profile',
            ]) {
                throw new ConfigurationException('Configuration key '.$key.' must contain the canonical profile fields.');
            }

            $provider = self::enum($enumParser, $key.'.provider_profile', $value['provider_profile'], self::PROVIDER_PROFILE_ALIASES);
            $adapter = self::enum($enumParser, $key.'.adapter', $value['adapter'], self::PROVIDER_PROFILE_ALIASES);
            if ($provider !== $adapter) {
                throw new ConfigurationException('Configuration key '.$key.' must bind one provider profile to the matching adapter.');
            }

            $models = self::stringList($key.'.models', $value['models']);
            $efforts = self::stringList($key.'.efforts', $value['efforts']);
            $roles = [];
            foreach (self::stringList($key.'.roles', $value['roles']) as $role) {
                $parsed = self::enum($enumParser, $key.'.roles', $role, array_column(AgentRole::cases(), 'value'));
                $roles[] = AgentRole::from($parsed);
            }
            $statusValue = self::enum(
                $enumParser,
                $key.'.capability_status',
                $value['capability_status'],
                array_column(CapabilityStatus::cases(), 'value'),
            );
            if (! is_string($value['runtime_profile']) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value['runtime_profile']) !== 1) {
                throw new ConfigurationException('Configuration key '.$key.'.runtime_profile is invalid.');
            }

            $profiles[$id] = new AgentProfile(
                $id,
                $provider,
                $adapter,
                $models,
                $efforts,
                $roles,
                CapabilityStatus::from($statusValue),
                $value['runtime_profile'],
            );
            $aliases[$provider] = true;
        }

        foreach (self::PROVIDER_PROFILE_ALIASES as $alias) {
            if (! isset($aliases[$alias])) {
                throw new ConfigurationException('Configuration key ai6.agent_profiles is missing provider profile '.$alias.'.');
            }
        }

        return new self($profiles);
    }

    /** @return list<AgentProfile> */
    public function all(): array
    {
        return array_values($this->profiles);
    }

    /** @return list<string> */
    public function profileNames(): array
    {
        return array_keys($this->profiles);
    }

    /** @return list<string> */
    public function allEfforts(): array
    {
        $efforts = [];
        foreach ($this->profiles as $profile) {
            $efforts = [...$efforts, ...$profile->efforts];
        }
        $efforts = array_values(array_unique($efforts));
        sort($efforts, SORT_STRING);

        return $efforts;
    }

    public function has(string $profileId): bool
    {
        return isset($this->profiles[$profileId]);
    }

    public function get(string $profileId): AgentProfile
    {
        return $this->profiles[$profileId]
            ?? throw new AgentProfileSelectionException(AgentProfileSelectionError::PROFILE_UNKNOWN);
    }

    public function supportsCombination(string $profileId, AgentRole $role, string $model, string $effort): bool
    {
        return isset($this->profiles[$profileId]) && $this->profiles[$profileId]->supports($role, $model, $effort);
    }

    public function supportsRoleEffort(string $profileId, AgentRole $role, string $effort): bool
    {
        if (! isset($this->profiles[$profileId])) {
            return false;
        }
        $profile = $this->profiles[$profileId];

        return in_array($role, $profile->roles, true) && in_array($effort, $profile->efforts, true);
    }

    public function resolve(string $profileId, AgentRole $role, string $model, string $effort): AgentSelection
    {
        $profile = $this->get($profileId);
        if (! $profile->supports($role, $model, $effort)) {
            throw new AgentProfileSelectionException(AgentProfileSelectionError::COMBINATION_NOT_ALLOWED);
        }
        if (! $profile->capabilityStatus->selectable()) {
            throw new AgentProfileSelectionException(AgentProfileSelectionError::CAPABILITY_NOT_AVAILABLE);
        }

        return new AgentSelection($profile, $role, $model, $effort);
    }

    /** @param non-empty-list<string> $allowed */
    private static function enum(StrictEnumParser $parser, string $key, mixed $value, array $allowed): string
    {
        $parsed = $parser->parse($key, $value, $allowed);
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }

    /** @return non-empty-list<string> */
    private static function stringList(string $key, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new ConfigurationException('Configuration key '.$key.' must be a non-empty list.');
        }
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $item) !== 1) {
                throw new ConfigurationException('Configuration key '.$key.' contains an invalid identifier.');
            }
            $strings[] = $item;
        }
        if (count(array_unique($strings)) !== count($strings)) {
            throw new ConfigurationException('Configuration key '.$key.' must not contain duplicates.');
        }

        return $strings;
    }
}
