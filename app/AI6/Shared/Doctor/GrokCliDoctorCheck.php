<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\GrokCliConfiguration;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Redaction\Redactor;

/** No model turn or credential read. Native probes and human runtime evidence stay distinct. */
final readonly class GrokCliDoctorCheck implements DoctorCheck
{
    public function __construct(private ?ControlProcessRunner $processes = null) {}

    public function label(): string
    {
        return 'Grok-CLI';
    }

    public function run(): DoctorCheckResult
    {
        return app(ProviderCapabilityReport::class)->doctor('grok_cli');
    }

    /** @param null|\Closure(string, \Closure(): void): void $probeOnce */
    public function probeCombination(string $profileId, AgentRole $selectedRole, string $selectedModel, string $selectedEffort, ?\Closure $probeOnce = null): DoctorCheckResult
    {
        ProviderOnboarding::assertAgent();
        try {
            $configuration = GrokCliConfiguration::fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Fehler' => 'grok_configuration_invalid']);
        }
        if (! $configuration->binaryPresent() && $configuration->pinnedVersion === '') {
            return new DoctorCheckResult(true, ['Statische Prüfung' => 'nicht eingerichtet; Grok-Profile gesperrt', 'Reale CLI-Evidenz' => 'nicht erbracht']);
        }
        $adapter = new GrokCliAdapter($configuration, app(AgentInputLimits::class), app(Redactor::class), app(RestrictedJsonDecoder::class), app(AgentProfileRegistry::class), $this->processes);
        $details = ['Transport' => 'Promptdatei / streaming-messages-json; neue Invocation ohne Resume',
            'Home und Auth' => 'read-only; Sessiondateien im Ergebnisverzeichnis; Auth nur im Agenten',
            'Tools' => 'read_file, list_dir, grep; Shell, Schreiben, Delegation, Memory, URL und MCP geschlossen',
            'Reale CLI-Evidenz' => 'nicht erbracht', 'Sandboxvorbereitung' => 'nicht erbracht', 'Linux-/Tool-/Discovery-/Credentialnachweis' => 'separater menschlicher Nachweis (AI6-041/MG-01)'];
        $passed = true;
        foreach (app(AgentProfileRegistry::class)->configured() as $profile) {
            if ($profile->id !== $profileId || $profile->providerProfileAlias !== GrokCliAdapter::PROVIDER_ALIAS) {
                continue;
            }
            foreach ($profile->roles as $role) {
                foreach ($profile->models as $model) {
                    foreach ($profile->efforts as $effort) {
                        if ($role !== $selectedRole || $model !== $selectedModel || $effort !== $selectedEffort) {
                            continue;
                        }
                        $key = $profile->id.' / '.$role->value.' / '.$model.' / '.$effort;
                        try {
                            $runtime = app(ProviderRuntimeProfileRegistry::class)->get($profile->runtimeProfileId);
                            $adapter->assertSelection($runtime, $role, $model, $effort, requireEvidence: false);
                            $details[$key.' statisch'] = 'OK';
                            $details[$key.' Evidenzbindung'] = $configuration->evidenceKey($runtime, $role, $model, $effort);
                            if ($probeOnce === null) {
                                $this->probe($adapter, $profile->runtimeProfileId, $model);
                            } else {
                                $probeOnce($runtime->hash.':'.$model, fn () => $this->probe($adapter, $profile->runtimeProfileId, $model));
                            }
                            $details[$key.' Sandboxvorbereitung'] = 'Sandbox vorbereitet; credentialfreie Init-/Auth-Probe, keine Sicherheitsabnahme (AI6-041/MG-01)';
                            $details['Sandboxvorbereitung'] = 'Sandbox vorbereitet';
                            $details['Reale CLI-Evidenz'] = 'Version und deaktivierte Erweiterungsoberfläche geprüft; kein Modellturn';
                            $adapter->assertSelection($runtime, $role, $model, $effort);
                            $details[$key] = 'gebundener Laufzeitnachweis konfiguriert; Profilstatus: '.$profile->capabilityStatus->value;
                        } catch (AgentExecutionException $exception) {
                            $passed = false;
                            if (in_array($exception->reason, ['agent_grok_sandbox_unprepared', 'agent_grok_sandbox_role_unverifiable'], true)) {
                                $details[$key.' Sandboxvorbereitung'] = $exception->reason === 'agent_grok_sandbox_role_unverifiable'
                                    ? 'in dieser Rolle nicht nachweisbar (AI6-041/MG-01)' : 'nicht vorbereitet';
                            }
                            $details[$key] = 'gesperrt: '.$exception->reason;
                        } catch (\Throwable) {
                            $passed = false;
                            $details[$key] = 'gesperrt: grok_probe_unavailable';
                        }
                    }
                }
            }
        }

        return new DoctorCheckResult($passed, $details);
    }

    private function probe(GrokCliAdapter $adapter, string $runtimeId, string $model): void
    {
        if (! is_writable(ProviderOnboarding::path('private_root'))) {
            throw new AgentExecutionException('agent_grok_sandbox_role_unverifiable');
        }
        app(ExecutionHomeManager::class)->withProbeHome('grok_cli', $runtimeId,
            static function (ExecutionHome $home, InstructionSnapshot $snapshot) use ($adapter, $model): void {
                $adapter->probe($home, $snapshot, GrokCliConfiguration::environment($home), static function (): void {}, ProcessPolicyName::CONTROL);
                $adapter->probeSandbox($home, $model);
            });
    }
}
