<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
use App\AI6\Shared\Doctor\DoctorCheckResult;
use App\AI6\Shared\Doctor\GitHubCopilotCliDoctorCheck;
use App\AI6\Shared\Doctor\GrokCliDoctorCheck;
use Throwable;

/** Native checks run here, never in the web/worker Doctor readers. */
final class ProviderCapabilityPublisher
{
    private int $lastDuration = 0;

    private ?\Closure $roleHeartbeat = null;

    public function __construct(private readonly ProviderCredentialStore $store, private readonly ProviderCapabilityReport $reports) {}

    public function pulse(string $bootId): void
    {
        ProviderOnboarding::assertAgent();
        $root = ProviderOnboarding::path('presence_root');
        if (trim(AgentExecutionProcessor::readBytes($root.'/boot-id', 64)) !== $bootId) {
            throw new CredentialProjectionException('The provider supervisor boot changed.');
        }
        $this->store->atomic($root.'/heartbeat.json', json_encode(['boot_id' => $bootId, 'recorded_at' => time()], JSON_THROW_ON_ERROR), 0644);
        if ($this->roleHeartbeat !== null) {
            $heartbeat = $this->roleHeartbeat;
            $this->roleHeartbeat = null;
            try {
                $heartbeat();
            } finally {
                $this->roleHeartbeat = $heartbeat;
            }
        }
    }

    /** @param null|\Closure(): void $heartbeat */
    public function due(?\Closure $heartbeat = null): void
    {
        $this->roleHeartbeat = $heartbeat;
        $started = time();
        $checked = false;
        try {
            foreach (['codex_cli', 'grok_cli', 'github_copilot_cli'] as $alias) {
                $document = $this->reports->read($alias, false);
                $interval = max(0, min(ProviderOnboarding::seconds('probe_interval_seconds'),
                    ProviderOnboarding::seconds('max_age_seconds') - $this->lastDuration));
                if ($document !== null && $document['boot_id'] === $this->reports->boot()
                    && time() - $document['checked_at'] < $interval) {
                    continue;
                }
                $checked = true;
                try {
                    $this->recheck($alias);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            if ($checked) {
                $this->lastDuration = time() - $started;
            }
        } finally {
            $this->roleHeartbeat = null;
        }
    }

    /** @param null|\Closure(): void $heartbeat */
    public function recheck(string $alias, ?\Closure $heartbeat = null): void
    {
        $previous = $this->roleHeartbeat;
        $this->roleHeartbeat = $heartbeat ?? $previous;
        try {
            $this->probe($alias);
        } finally {
            $this->roleHeartbeat = $previous;
        }
    }

    private function probe(string $alias): void
    {
        ProviderOnboarding::assertAgent();
        $configuration = ProviderOnboarding::configuration($alias);
        $boot = $this->reports->boot();
        $checkedAt = time();
        $snapshot = $this->store->locked(function () use ($alias): ?string {
            $directory = ProviderOnboarding::path('store_root').'/'.$alias;
            if (! is_dir($directory)) {
                return null;
            }

            return $this->store->generation($alias);
        });
        if ($snapshot === null) {
            $this->store->replace($alias, null);
            $snapshot = $this->store->generation($alias);
        }
        // Compare the persisted revocation generation, including when the old
        // report expired or belongs to the preceding boot. A torn mutation must
        // be repaired by an explicit login/logout, never by a delayed recheck.
        if (! $this->unrevoked($alias, $snapshot)) {
            return;
        }
        $rows = [];
        $catalog = null;
        $codexProbe = null;
        $probes = [];
        // Cache only within this generation-bound recheck. Tuple assertions
        // remain independent; identical native surfaces run just once.
        $probeOnce = static function (string $key, \Closure $probe) use (&$probes): void {
            if (! array_key_exists($key, $probes)) {
                try {
                    $probe();
                    $probes[$key] = null;
                } catch (Throwable $exception) {
                    $probes[$key] = $exception;
                }
            }
            if ($probes[$key] instanceof Throwable) {
                throw $probes[$key];
            }
        };
        foreach (app(AgentProfileRegistry::class)->configured() as $profile) {
            if ($profile->providerProfileAlias !== $alias) {
                continue;
            }
            foreach ($profile->roles as $role) {
                foreach ($profile->models as $model) {
                    foreach ($profile->efforts as $effort) {
                        $reason = 'installation';
                        $status = 'unavailable';
                        $bindings = $this->reports->bindings($profile, $role, $model, $effort);
                        if ($configuration->binaryPresent()) {
                            $reason = 'auth';
                            $file = ProviderOnboarding::path('store_root').'/'.$alias.'/'.ProviderOnboarding::filename($alias);
                            if (is_file($file) && ! is_link($file)) {
                                $status = 'degraded';
                                $reason = 'probe';
                                try {
                                    if ($alias === 'codex_cli' && $codexProbe === null) {
                                        $codexProbe = new DoctorCheckResult(false, ['Fehler' => 'probe']);
                                        $codexProbe = app(ExecutionHomeManager::class)->withProbeHome($alias, $profile->runtimeProfileId,
                                            fn (ExecutionHome $home) => app(CodexCliDoctorCheck::class)->probeCombination($home));
                                    }
                                    $result = match ($alias) {
                                        'codex_cli' => $codexProbe,
                                        'grok_cli' => app(GrokCliDoctorCheck::class)->probeCombination($profile->id, $role, $model, $effort, $probeOnce),
                                        default => app(GitHubCopilotCliDoctorCheck::class)->probeCombination($profile->id, $role, $model, $effort, $probeOnce),
                                    };
                                    if ($result->passed && $catalog === null) {
                                        $catalog = [];
                                        $catalog = app(ProviderLogin::class)->verifiedCatalog($alias, $snapshot);
                                    }
                                    if ($result->passed && in_array($effort, $catalog[$model] ?? [], true)) {
                                        $status = 'ready';
                                        $reason = 'ready';
                                    } elseif (str_contains(implode(' ', $result->details), 'unproven') || str_contains(implode(' ', $result->details), 'proof_invalid')) {
                                        $reason = 'runtime';
                                    }
                                } catch (Throwable) {
                                    // Native diagnostics and credential-bearing exceptions never enter the report.
                                }
                            }
                        }
                        $rows[] = ['profile' => $profile->id, 'role' => $role->value, 'model' => $model, 'effort' => $effort,
                            'status' => $status, 'reason' => $reason, 'version' => $configuration->pinnedVersion, ...$bindings];
                    }
                }
            }
        }
        $this->store->locked(function () use ($alias, $snapshot, $rows, $checkedAt, $boot): void {
            if ($this->store->generation($alias) === $snapshot && $this->unrevoked($alias, $snapshot) && $this->reports->boot() === $boot) {
                $this->store->publish($alias, $snapshot, $rows, $checkedAt, $boot);
            }
        });
    }

    private function unrevoked(string $alias, string $generation): bool
    {
        try {
            return $this->reports->generation($alias, false) === $generation;
        } catch (Throwable) {
            return false;
        }
    }
}
