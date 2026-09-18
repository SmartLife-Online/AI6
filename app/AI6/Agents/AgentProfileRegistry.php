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

    /** Immutable DTOs, not cached evidence. Each resolution still reads the report.
     * @var array<string, array<string, AgentProfile>>
     */
    private array $statusProjections;

    /** @param array<string, AgentProfile> $profiles
     * @param  null|\Closure(): ProviderCapabilityReport  $reports
     */
    private function __construct(array $profiles, private ?\Closure $reports = null)
    {
        ksort($profiles, SORT_STRING);
        $this->profiles = $profiles;
        $projections = [];
        foreach ($profiles as $profile) {
            foreach (CapabilityStatus::cases() as $status) {
                $projections[$profile->id][$status->value] = new AgentProfile($profile->id, $profile->providerProfileAlias,
                    $profile->adapterId, $profile->models, $profile->efforts, $profile->roles, $status, $profile->runtimeProfileId);
            }
        }
        $this->statusProjections = $projections;
    }

    public static function fromConfiguredValues(StrictEnumParser $enumParser): self
    {
        $configured = config('ai6.agent_profiles');

        $parsed = self::fromArray(is_array($configured) ? $configured : [], $enumParser);

        return new self($parsed->profiles, static fn (): ProviderCapabilityReport => app(ProviderCapabilityReport::class));
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
        return array_map(fn (AgentProfile $profile): AgentProfile => $this->get($profile->id), $this->configured());
    }

    /** Static allowlist only, for native probes before readiness (AGT-010).
     * @return list<AgentProfile>
     */
    public function configured(): array
    {
        return array_values($this->profiles);
    }

    /**
     * The existing queue scheduler serializes this registry for its trusted
     * binding fingerprint. Include current DTOs, never the lazy resolver.
     *
     * @return array{profiles: list<AgentProfile>, reports: array<string, mixed>}
     */
    public function __serialize(): array
    {
        $reports = [];
        if ($this->reports !== null) {
            foreach (self::PROVIDER_PROFILE_ALIASES as $alias) {
                if ($alias !== 'fake') {
                    $document = ($this->reports)()->read($alias);
                    // Liveness metadata is not a capability change. Expired or
                    // invalid evidence still becomes null and schedules reevaluation.
                    $reports[$alias] = $document === null ? null : array_intersect_key($document, array_flip(['alias', 'generation', 'rows']));
                }
            }
        }

        return ['profiles' => $this->all(), 'reports' => $reports];
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Agent profiles must be rebuilt from trusted configuration.');
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
        $profile = $this->profiles[$profileId]
            ?? throw new AgentProfileSelectionException(AgentProfileSelectionError::PROFILE_UNKNOWN);
        if ($this->reports === null || $profile->providerProfileAlias === 'fake') {
            return $profile;
        }
        $status = ($this->reports)()->diagnosis($profile)['status'] === 'ready' ? CapabilityStatus::AVAILABLE
            : ($profile->capabilityStatus === CapabilityStatus::UNCHECKED ? CapabilityStatus::UNCHECKED : CapabilityStatus::UNAVAILABLE);

        return $this->statusProjections[$profile->id][$status->value];
    }

    public function supportsCombination(string $profileId, AgentRole $role, string $model, string $effort): bool
    {
        return isset($this->profiles[$profileId]) && $this->profiles[$profileId]->supports($role, $model, $effort);
    }

    /**
     * Whether a registered profile of this provider alias approves the
     * role/model/effort a run slot carries (AGT-002). The worker asks this
     * before it seals a turn, so no value that left the allowlist since the
     * approval — and no free value from project or UI — reaches an adapter.
     */
    public function supportsProviderSelection(string $providerProfileAlias, AgentRole $role, string $model, string $effort): bool
    {
        foreach ($this->profiles as $profile) {
            if ($profile->providerProfileAlias === $providerProfileAlias && $profile->supports($role, $model, $effort)
                && ($this->reports === null || ($this->reports)()->diagnosis($profile, $role, $model, $effort)['status'] === 'ready')) {
                return true;
            }
        }

        return false;
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
        if (! $profile->capabilityStatus->selectable()
            || ($this->reports !== null && ($this->reports)()->diagnosis($profile, $role, $model, $effort)['status'] !== 'ready')) {
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
