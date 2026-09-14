<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\GrokCliConfiguration;
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
final readonly class GrokCliDoctorCheck implements DoctorCheck
{
    public function __construct(private ?ControlProcessRunner $processes = null) {}

    public function label(): string
    {
        return 'Grok-CLI';
    }

    public function run(): DoctorCheckResult
    {
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
        foreach (app(AgentProfileRegistry::class)->all() as $profile) {
            if ($profile->providerProfileAlias !== GrokCliAdapter::PROVIDER_ALIAS) {
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
                            $this->probe($adapter, $profile->runtimeProfileId, $model);
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
        try {
            $input = AgentExecutionProcessor::inputRoot();
            $output = AgentExecutionProcessor::outputRoot();
            if (! is_writable($input) || ! is_writable($output)) {
                throw new AgentExecutionException('agent_grok_sandbox_role_unverifiable');
            }
        } catch (AgentExecutionException) {
            throw new AgentExecutionException('agent_grok_sandbox_role_unverifiable');
        }
        $name = '/execution-'.bin2hex(random_bytes(16));
        $root = $input.$name;
        $resultRoot = $output.$name;
        $home = null;
        $created = [];
        // A credential-free probe uses the central materializer with an explicit ephemeral revision.
        $manager = new ExecutionHomeManager(app(CanonicalJson::class), new CredentialRevisionRegistry(['grok_cli' => 'doctor']), app(ProviderRuntimeProfileRegistry::class));
        try {
            foreach ([$root, $resultRoot, $root.'/export'] as $directory) {
                if (! @mkdir($directory, 0700)) {
                    throw new AgentExecutionException('agent_grok_sandbox_role_unverifiable');
                }
                $created[] = $directory;
            }
            $snapshot = new InstructionSnapshot('grok_cli', [], hash('sha256', 'grok-doctor'));
            $home = $manager->create($root, $resultRoot, 'doctor', null, $root.'/export',
                app(InstructionProfileRegistry::class)->get('grok_cli'), $snapshot,
                app(ProviderRuntimeProfileRegistry::class)->get($runtimeId), new CredentialProjection('grok_cli', 'doctor', []));
            $adapter->probe($home, $snapshot, GrokCliConfiguration::environment($home), static function (): void {}, ProcessPolicyName::CONTROL);
            $adapter->probeSandbox($home, $model);
        } finally {
            if ($home !== null) {
                $manager->destroy($home);
            }
            foreach (array_reverse($created) as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }
}
