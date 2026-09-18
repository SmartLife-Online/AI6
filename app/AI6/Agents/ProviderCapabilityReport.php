<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Doctor\DoctorCheckResult;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\RedactionContext;
use Throwable;

/** The shared, bounded, generation-bound evidence channel (AGT-010).
 * @phpstan-type CapabilityRow array{profile: string, role: string, model: string, effort: string, status: string, reason: string, version: string, binary_sha256: string, runtime_profile_hash: string, binding: string}
 * @phpstan-type CapabilityDocument array{schema: string, alias: string, generation: string, boot_id: string, checked_at: int, rows: list<CapabilityRow>}
 */
final readonly class ProviderCapabilityReport
{
    public const REASONS = [
        'ready' => 'Installation, Anmeldung und Laufzeitnachweis geprüft.',
        'installation' => 'Gepinnte CLI fehlt oder ist abweichend.',
        'auth' => 'Keine gültige Anmeldung vorhanden.',
        'probe' => 'Native Auth-, Modell- oder Laufzeitprüfung nicht vollständig bestanden.',
        'runtime' => 'Menschlicher Laufzeitnachweis fehlt oder ist ungültig.',
        'evidence' => 'Aktueller, vollständig gebundener Agentbericht fehlt.',
    ];

    public function __construct(private RestrictedJsonDecoder $json, private CanonicalJson $canonical) {}

    /**
     * Read current external evidence; even identical calls can observe a new report.
     *
     * @return CapabilityDocument|null
     *
     * @phpstan-impure
     */
    public function read(string $alias, bool $fresh = true): ?array
    {
        return $this->document($alias, $fresh);
    }

    /** @return CapabilityDocument|null */
    private function document(string $alias, bool $fresh): ?array
    {
        try {
            ProviderOnboarding::configuration($alias);
            $document = $this->json->decode(AgentExecutionProcessor::readBytes(ProviderOnboarding::path('report_root').'/'.$alias.'.json', 65536), new RedactionContext('provider', null, 'capability'));
            if (array_keys($document) !== ['schema', 'alias', 'generation', 'boot_id', 'checked_at', 'rows']
                || $document['schema'] !== 'ai6.provider-capability.v1' || $document['alias'] !== $alias
                || ! is_string($document['generation']) || preg_match('/\A[0-9a-f]{32}\z/D', $document['generation']) !== 1
                || ! is_string($document['boot_id']) || preg_match('/\A[0-9a-f]{32}\z/D', $document['boot_id']) !== 1
                || ($fresh && $document['boot_id'] !== $this->boot())
                || ! is_int($document['checked_at']) || $document['checked_at'] > time()
                || ($fresh && $document['checked_at'] < time() - ProviderOnboarding::seconds('max_age_seconds'))
                || ! is_array($document['rows']) || ! array_is_list($document['rows'])) {
                return null;
            }
            $seen = [];
            $rows = [];
            foreach ($document['rows'] as $row) {
                if (! is_array($row) || array_keys($row) !== ['profile', 'role', 'model', 'effort', 'status', 'reason', 'version', 'binary_sha256', 'runtime_profile_hash', 'binding']
                    || count(array_filter($row, is_string(...))) !== count($row)) {
                    return null;
                }
                $row = array_filter($row, is_string(...));
                if (! in_array($row['status'], ['ready', 'degraded', 'unavailable'], true)
                    || ! isset(self::REASONS[$row['reason']]) || ($row['status'] === 'ready') !== ($row['reason'] === 'ready')
                    || preg_match('/\A[0-9a-f]{64}\z/D', $row['binding']) !== 1
                    || preg_match('/\A[0-9a-f]{64}\z/D', $row['runtime_profile_hash']) !== 1
                    || ($row['binary_sha256'] !== '' && preg_match('/\A[0-9a-f]{64}\z/D', $row['binary_sha256']) !== 1)) {
                    return null;
                }
                $key = implode('\0', [$row['profile'], $row['role'], $row['model'], $row['effort']]);
                if (isset($seen[$key])) {
                    return null;
                }
                $seen[$key] = true;
                $rows[] = ['profile' => $row['profile'], 'role' => $row['role'], 'model' => $row['model'], 'effort' => $row['effort'],
                    'status' => $row['status'], 'reason' => $row['reason'], 'version' => $row['version'],
                    'binary_sha256' => $row['binary_sha256'], 'runtime_profile_hash' => $row['runtime_profile_hash'], 'binding' => $row['binding']];
            }

            return ['schema' => $document['schema'], 'alias' => $alias, 'generation' => $document['generation'],
                'boot_id' => $document['boot_id'], 'checked_at' => $document['checked_at'], 'rows' => $rows];
        } catch (Throwable) {
            return null;
        }
    }

    /** Independent supervisor presence is never derived from a report or turn output. */
    public function boot(bool $fresh = true): string
    {
        $root = ProviderOnboarding::path('presence_root');
        $boot = trim(AgentExecutionProcessor::readBytes($root.'/boot-id', 64));
        $pulse = $this->json->decode(AgentExecutionProcessor::readBytes($root.'/heartbeat.json', 512), new RedactionContext('provider', null, 'presence'));
        if (preg_match('/\A[0-9a-f]{32}\z/D', $boot) !== 1 || array_keys($pulse) !== ['boot_id', 'recorded_at']
            || ! is_string($pulse['boot_id']) || preg_match('/\A[0-9a-f]{32}\z/D', $pulse['boot_id']) !== 1
            || ! is_int($pulse['recorded_at']) || $pulse['recorded_at'] > time()
            || ($fresh && ($pulse['boot_id'] !== $boot
                || $pulse['recorded_at'] < time() - ProviderOnboarding::seconds('presence_max_age_seconds')))) {
            throw new CredentialProjectionException('The current agent boot is unavailable.');
        }

        return $boot;
    }

    /** Ongoing turns check revocation, independently of capability/presence expiry. */
    public function generation(string $alias, bool $fresh = true): string
    {
        $document = $this->document($alias, $fresh);
        if ($document === null) {
            throw new CredentialProjectionException('The current provider generation is unavailable.');
        }

        return $document['generation'];
    }

    /** @return array{status: string, reason: string, version: string} */
    public function diagnosis(AgentProfile $profile, ?AgentRole $role = null, ?string $model = null, ?string $effort = null): array
    {
        $missing = ['status' => 'unavailable', 'reason' => 'evidence', 'version' => ''];
        if ($profile->providerProfileAlias === 'fake') {
            return ['status' => $profile->capabilityStatus->selectable() ? 'ready' : 'unavailable', 'reason' => $profile->capabilityStatus->selectable() ? 'ready' : 'evidence', 'version' => ''];
        }
        $document = $this->read($profile->providerProfileAlias);
        if ($document === null) {
            return $missing;
        }
        $diagnoses = [];
        foreach ($profile->roles as $candidateRole) {
            foreach ($profile->models as $candidateModel) {
                foreach ($profile->efforts as $candidateEffort) {
                    if (($role !== null && $candidateRole !== $role) || ($model !== null && $candidateModel !== $model) || ($effort !== null && $candidateEffort !== $effort)) {
                        continue;
                    }
                    $found = $missing;
                    foreach ($document['rows'] as $row) {
                        if ($row['profile'] === $profile->id && $row['role'] === $candidateRole->value && $row['model'] === $candidateModel && $row['effort'] === $candidateEffort) {
                            try {
                                $bindings = $this->bindings($profile, $candidateRole, $candidateModel, $candidateEffort);
                                if ($bindings === array_intersect_key($row, $bindings)) {
                                    $found = ['status' => $row['status'], 'reason' => $row['reason'], 'version' => $row['version']];
                                    if ($row['version'] !== ProviderOnboarding::configuration($profile->providerProfileAlias)->pinnedVersion) {
                                        $found = $missing;
                                    } elseif ($row['status'] === 'ready' && ! $this->humanEvidence($profile, $candidateRole, $candidateModel, $candidateEffort)) {
                                        $found = ['status' => 'degraded', 'reason' => 'runtime', 'version' => $row['version']];
                                    }
                                }
                            } catch (Throwable) {
                            }
                        }
                    }
                    if ($found['status'] === 'ready') {
                        return $found;
                    }
                    $diagnoses[] = $found;
                }
            }
        }
        // A profile remains offered when at least one tuple is ready. Resolve
        // and provider selection still require the exact tuple below it.
        foreach (['ready', 'degraded', 'unavailable'] as $status) {
            foreach ($diagnoses as $diagnosis) {
                if ($diagnosis['status'] === $status) {
                    return $diagnosis;
                }
            }
        }

        return $missing;
    }

    private function humanEvidence(AgentProfile $profile, AgentRole $role, string $model, string $effort): bool
    {
        $configuration = ProviderOnboarding::configuration($profile->providerProfileAlias);
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get($profile->runtimeProfileId);
        if ($configuration instanceof CodexCliConfiguration) {
            CodexCliAdapter::assertRuntimeProfile($runtime);

            return in_array($configuration->pinnedVersion, CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS, true)
                && $configuration->sandboxProofBinds() && in_array($role, CodexCliAdapter::SUPPORTED_ROLES, true)
                && isset(CodexCliAdapter::VERIFIED_MODELS[$model])
                && ($effort === 'provider_default' || in_array($effort, CodexCliAdapter::VERIFIED_MODELS[$model], true));
        }
        if ($configuration instanceof GrokCliConfiguration) {
            GrokCliAdapter::assertRuntimeProfile($runtime);
            $pin = GrokCliConfiguration::TRANSPORT_VERSION;
        } else {
            GitHubCopilotCliAdapter::assertRuntimeProfile($runtime);
            $pin = GitHubCopilotCliConfiguration::TRANSPORT_VERSION;
        }

        return $configuration->pinnedVersion === $pin && in_array($configuration->evidenceKey($runtime, $role, $model, $effort), $configuration->capabilityEvidence, true);
    }

    public function binding(AgentProfile $profile, AgentRole $role, string $model, string $effort): string
    {
        return $this->bindings($profile, $role, $model, $effort)['binding'];
    }

    /** @return array{binary_sha256: string, runtime_profile_hash: string, binding: string} */
    public function bindings(AgentProfile $profile, AgentRole $role, string $model, string $effort): array
    {
        $configuration = ProviderOnboarding::configuration($profile->providerProfileAlias);
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get($profile->runtimeProfileId);
        $binary = $configuration->binaryPresent() ? app(ProviderBinaryDigest::class)->sha256($configuration->binary) : false;

        $binding = hash('sha256', $this->canonical->normalizeAndEncode([
            'profile' => $profile->id, 'alias' => $profile->providerProfileAlias, 'role' => $role->value,
            'model' => $model, 'effort' => $effort, 'version' => $configuration->pinnedVersion,
            'binary' => $binary === false ? null : $binary,
            'runtime' => $runtime->hash, 'human_evidence' => $configuration instanceof CodexCliConfiguration
                ? $configuration->sandboxProof : (in_array($configuration->evidenceKey($runtime, $role, $model, $effort), $configuration->capabilityEvidence, true)
                    ? $configuration->evidenceKey($runtime, $role, $model, $effort) : null),
            'platform' => PHP_OS_FAMILY,
            'transport' => hash_file('sha256', __DIR__.'/'.match ($profile->providerProfileAlias) {
                'codex_cli' => 'CodexCliAdapter.php', 'grok_cli' => 'GrokCliAdapter.php', default => 'GitHubCopilotCliAdapter.php',
            }),
            'home' => hash_file('sha256', __DIR__.'/ExecutionHomeManager.php'),
            'namespace' => hash_file('sha256', base_path('app/AI6/Shared/Process/AgentProcessScope.php')),
            'seccomp' => hash_file('sha256', base_path('docker/agent-seccomp-moby-29.6.1.json')),
        ]));

        return ['binary_sha256' => $binary === false ? '' : $binary, 'runtime_profile_hash' => $runtime->hash, 'binding' => $binding];
    }

    public function doctor(string $alias): DoctorCheckResult
    {
        $details = [];
        $passed = true;
        foreach (app(AgentProfileRegistry::class)->configured() as $profile) {
            if ($profile->providerProfileAlias !== $alias) {
                continue;
            }
            $diagnosis = $this->diagnosis($profile);
            $optional = $alias === 'github_copilot_cli'
                && count(array_filter($profile->models, static fn (string $model): bool => str_starts_with($model, 'claude-'))) === count($profile->models);
            if (! $optional) {
                $passed = $passed && $diagnosis['status'] === 'ready';
            }
            $details[$profile->id] = $diagnosis['status'].' · '.self::REASONS[$diagnosis['reason']]
                .($diagnosis['version'] === '' ? '' : ' CLI '.$diagnosis['version']);
        }

        return new DoctorCheckResult($passed, $details);
    }
}
