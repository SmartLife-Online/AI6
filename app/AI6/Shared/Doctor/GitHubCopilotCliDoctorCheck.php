<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshot;
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
        foreach (app(AgentProfileRegistry::class)->all() as $profile) {
            if ($profile->providerProfileAlias !== GitHubCopilotCliAdapter::PROVIDER_ALIAS) {
                continue;
            }
            foreach ($profile->roles as $role) {
                foreach ($profile->models as $model) {
                    foreach ($profile->efforts as $effort) {
                        $key = $profile->id.' / '.$role->value.' / '.$model.' / '.$effort;
                        try {
                            $runtime = app(ProviderRuntimeProfileRegistry::class)->get($profile->runtimeProfileId);
                            $adapter->assertSelection($runtime, $role, $model, $effort, requireEvidence: false);
                            $details[$key.' statisch'] = 'OK';
                            $details[$key.' Evidenzbindung'] = $configuration->evidenceKey($runtime, $role, $model, $effort);
                            $this->probe($adapter, $profile->runtimeProfileId);
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
        $root = storage_path('framework/copilot-doctor-'.bin2hex(random_bytes(8)));
        $home = null;
        // A credential-free probe uses the central materializer with an explicit ephemeral revision.
        $manager = new ExecutionHomeManager(app(CanonicalJson::class), new CredentialRevisionRegistry(['github_copilot_cli' => 'doctor']), app(ProviderRuntimeProfileRegistry::class));
        try {
            foreach (['inputs', 'outputs', 'export'] as $directory) {
                if (! mkdir($root.'/'.$directory, 0700, true)) {
                    throw new AgentExecutionException('agent_copilot_probe_directory_unavailable');
                }
            }
            $snapshot = new InstructionSnapshot('github_copilot_cli', [], hash('sha256', 'copilot-doctor'));
            $home = $manager->create($root.'/inputs', $root.'/outputs', 'doctor', null, $root.'/export',
                app(InstructionProfileRegistry::class)->get('github_copilot_cli'), $snapshot,
                app(ProviderRuntimeProfileRegistry::class)->get($runtimeId), new CredentialProjection('github_copilot_cli', 'doctor', []));
            $adapter->probe($home, $snapshot, ['HOME' => $home->home, 'COPILOT_HOME' => $home->home, 'COPILOT_CACHE_HOME' => $home->resultDirectory.'/cache', 'TMPDIR' => $home->resultDirectory], static function (): void {}, ProcessPolicyName::CONTROL);
        } finally {
            if ($home !== null) {
                $manager->destroy($home);
            }
            foreach (['inputs', 'outputs', 'export'] as $directory) {
                if (is_dir($root.'/'.$directory)) {
                    rmdir($root.'/'.$directory);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
}
