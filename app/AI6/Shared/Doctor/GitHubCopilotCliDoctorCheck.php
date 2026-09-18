<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Redaction\Redactor;

/** No model turn or credential read. Native probes and human runtime evidence stay distinct. */
final readonly class GitHubCopilotCliDoctorCheck implements DoctorCheck
{
    public function __construct(private ?ControlProcessRunner $processes = null) {}

    public function label(): string
    {
        return 'GitHub-Copilot-CLI';
    }

    public function run(): DoctorCheckResult
    {
        return app(ProviderCapabilityReport::class)->doctor('github_copilot_cli');
    }

    /** @param null|\Closure(string, \Closure(): void): void $probeOnce */
    public function probeCombination(string $profileId, AgentRole $selectedRole, string $selectedModel, string $selectedEffort, ?\Closure $probeOnce = null): DoctorCheckResult
    {
        ProviderOnboarding::assertAgent();
        try {
            $configuration = GitHubCopilotCliConfiguration::fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Fehler' => 'copilot_configuration_invalid']);
        }
        if (! $configuration->binaryPresent() && $configuration->pinnedVersion === '') {
            return new DoctorCheckResult(true, ['Statische Prüfung' => 'nicht eingerichtet; Copilot-Profile gesperrt', 'Reale CLI-Evidenz' => 'nicht erbracht']);
        }
        $adapter = new GitHubCopilotCliAdapter($configuration, app(AgentInputLimits::class), app(Redactor::class), app(RestrictedJsonDecoder::class), app(AgentProfileRegistry::class), app(CanonicalJson::class), $this->processes);
        $details = ['Transport' => 'stdin / text; neue Invocation ohne Resume',
            'Home und Auth' => 'read-only; native Sessionablage read-only; Auth nur als Tokenprojektion im Agenten',
            'Tools' => 'view, glob, grep; Shell, Schreiben, Delegation, Memory, URL und MCP geschlossen',
            'Reale CLI-Evidenz' => 'nicht erbracht', 'Linux-/Tool-/Discovery-/Credentialnachweis' => 'separater menschlicher Nachweis (AI6-048/MG-01)'];
        $passed = true;
        foreach (app(AgentProfileRegistry::class)->configured() as $profile) {
            if ($profile->id !== $profileId || $profile->providerProfileAlias !== GitHubCopilotCliAdapter::PROVIDER_ALIAS) {
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
                                $this->probe($adapter, $profile->runtimeProfileId);
                            } else {
                                $probeOnce($runtime->hash, fn () => $this->probe($adapter, $profile->runtimeProfileId));
                            }
                            $details['Reale CLI-Evidenz'] = 'Version und deaktivierte Erweiterungsoberfläche geprüft; kein Modellturn';
                            $adapter->assertSelection($runtime, $role, $model, $effort);
                            $details[$key] = 'gebundener Laufzeitnachweis konfiguriert; Profilstatus: '.$profile->capabilityStatus->value;
                        } catch (AgentExecutionException $exception) {
                            $passed = false;
                            $details[$key] = 'gesperrt: '.$exception->reason;
                        } catch (\Throwable) {
                            $passed = false;
                            $details[$key] = 'gesperrt: copilot_probe_unavailable';
                        }
                    }
                }
            }
        }

        return new DoctorCheckResult($passed, $details);
    }

    private function probe(GitHubCopilotCliAdapter $adapter, string $runtimeId): void
    {
        app(ExecutionHomeManager::class)->withProbeHome('github_copilot_cli', $runtimeId,
            static function (ExecutionHome $home, InstructionSnapshot $snapshot) use ($adapter): void {
                $adapter->probe($home, $snapshot, ['COPILOT_AUTO_UPDATE' => 'false', 'HOME' => $home->home, 'COPILOT_HOME' => $home->home,
                    'COPILOT_CACHE_HOME' => $home->resultDirectory.'/cache', 'TMPDIR' => $home->resultDirectory],
                    static function (): void {}, ProcessPolicyName::CONTROL);
            });
    }
}
