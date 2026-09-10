<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentProfile;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;

/**
 * Capability doctor of the codex_cli transport (AGT-010). The static part
 * reads configuration and profiles only; the real CLI evidence is one
 * `--version` probe of the pinned binary and never a turn. Both are shown
 * separately, and a missing proof locks only this provider's profiles.
 */
final readonly class CodexCliDoctorCheck implements DoctorCheck
{
    private const VERSION_PROBE_TIMEOUT_SECONDS = 30;

    /**
     * The instance values and the process runner are resolved at run time,
     * like the keyring check resolves its ring, so `ai6:doctor` reports the
     * configuration of the moment it runs and its construction at console
     * boot starts nothing; tests hand in explicit collaborators instead.
     */
    public function __construct(
        private AgentProfileRegistry $profiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private ?ControlProcessRunner $processes = null,
        private ?CodexCliConfiguration $configuration = null,
    ) {}

    public function label(): string
    {
        return 'Codex-CLI';
    }

    public function run(): DoctorCheckResult
    {
        try {
            $configuration = $this->configuration ?? CodexCliConfiguration::fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Fehler' => 'codex_configuration_invalid']);
        }
        $binaryPresent = $configuration->binaryPresent();
        $pin = $configuration->pinnedVersion;
        if (! $binaryPresent && $pin === '') {
            return new DoctorCheckResult(true, [
                'Zustand' => 'nicht eingerichtet; Profile von codex_cli gesperrt',
                'Statische Prüfung' => 'übersprungen (weder Binary noch Versionspin konfiguriert)',
                'Reale CLI-Evidenz' => 'nicht erbracht',
            ]);
        }
        $details = [
            'Binary' => $binaryPresent ? 'konfiguriert und vorhanden' : 'fehlt',
            'Versionspin' => $pin === '' ? 'fehlt' : $pin,
            'Transportflags' => implode(' · ', CodexCliAdapter::TRANSPORT_FLAGS),
            'Transportnachweis' => in_array($pin, CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS, true)
                ? 'verifiziert für '.$pin
                : 'nicht erbracht (verifiziert: '.implode(', ', CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS).')',
            'Rollen und Modelle' => $this->profileSummary(),
            'Nachgewiesene Modelle' => $this->modelSummary(),
            'Sandbox- und Discoverygrenzen' => 'Sandbox read-only für Reviews, workspace-write nur im Änderungsausgang; Toolnetz aus;'
                .' CODEX_HOME = read-only Authprojektion; --ignore-user-config --ignore-rules --ephemeral;'
                .' deaktiviert: '.implode(', ', CodexCliAdapter::DISABLED_FEATURES)
                .'; ohne Schalter erlaubt: '.implode(', ', CodexCliAdapter::PERMITTED_ENABLED_FEATURES)
                .'; gebündelte Vendor-Skills werden nach $CODEX_HOME/skills/.system entpackt und scheitern an der'
                .' read-only Authprojektion — reale Startbarkeit unter dieser Grenze bleibt MG-01'
                .'; die Sandbox- und Toolnetzgrenze selbst kann die gepinnte CLI turnfrei nicht melden und wird'
                .' als gebundener Instanzwert AI6_CODEX_SANDBOX_PROOF nachgewiesen',
        ];
        $static = $this->staticFailure($binaryPresent, $pin);
        $details['Statische Prüfung'] = $static === null ? 'OK' : 'FEHLER ('.$static.')';
        if ($static !== null) {
            $details['Reale CLI-Evidenz'] = 'nicht erbracht';
            $details['Fehler'] = $static;

            return new DoctorCheckResult(false, $details);
        }
        $evidence = $this->versionEvidence($configuration);
        if ($evidence !== null) {
            $details['Reale CLI-Evidenz'] = 'FEHLER ('.$evidence.')';
            $details['Fehler'] = $evidence;

            return new DoctorCheckResult(false, $details);
        }
        $details['Reale CLI-Evidenz'] = $configuration->expectedVersionLine().' (gleich Pin)';
        $surface = $this->featureSurfaceEvidence($configuration);
        if ($surface !== null) {
            $details['Schutznachweis'] = 'FEHLER ('.$surface.')';
            $details['Fehler'] = $surface;

            return new DoctorCheckResult(false, $details);
        }
        $details['Schutznachweis'] = sprintf(
            '%d Schalter aus, %d ohne Schalter erlaubt (codex features list, ohne Turn)',
            count(CodexCliAdapter::DISABLED_FEATURES),
            count(CodexCliAdapter::PERMITTED_ENABLED_FEATURES),
        );
        // The feature surface is version evidence only. Whether the sandbox and
        // the tool-network boundary of that version hold in this runtime cannot
        // be derived here, so it stays a separately asserted, bound fact.
        $sandbox = $this->sandboxProofFailure($configuration);
        if ($sandbox !== null) {
            $details['Sandbox- und Toolnetznachweis'] = 'FEHLER ('.$sandbox.')';
            $details['Fehler'] = $sandbox;

            return new DoctorCheckResult(false, $details);
        }
        $details['Sandbox- und Toolnetznachweis'] = 'gebunden an '.$configuration->sandboxProof.' (AI6-033/MG-01)';
        $details['Profilzustand'] = 'startbar';

        return new DoctorCheckResult(true, $details);
    }

    /**
     * The sandbox and tool-network boundary is the one protection the pinned
     * CLI cannot report turn-free, so it is asserted as trusted instance
     * configuration and bound to the pin and the running platform. Without a
     * binding assertion this provider is never reported as startable.
     */
    private function sandboxProofFailure(CodexCliConfiguration $configuration): ?string
    {
        if ($configuration->sandboxProof === '') {
            return 'codex_sandbox_unproven';
        }

        return $configuration->sandboxProofBinds() ? null : 'codex_sandbox_proof_invalid';
    }

    private function staticFailure(bool $binaryPresent, string $pin): ?string
    {
        if (! $binaryPresent) {
            return 'codex_binary_missing';
        }
        if ($pin === '') {
            return 'codex_pin_missing';
        }
        if (! in_array($pin, CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS, true)) {
            return 'codex_transport_unverified';
        }
        foreach ($this->codexProfiles() as $profile) {
            foreach ($profile->roles as $role) {
                if (! in_array($role, CodexCliAdapter::SUPPORTED_ROLES, true)) {
                    return 'codex_profile_roles_invalid';
                }
            }
            if (in_array(CodexCliAdapter::PROVIDER_DEFAULT_EFFORT, $profile->models, true)) {
                return 'codex_model_unsupported';
            }
            // Every offered pair must be one the pinned catalog carries: the
            // profile may not offer a model or an effort the CLI would only
            // learn about from the provider at turn time.
            foreach ($profile->models as $model) {
                $verified = CodexCliAdapter::VERIFIED_MODELS[$model] ?? null;
                if ($verified === null) {
                    return 'codex_model_unverified';
                }
                foreach ($profile->efforts as $effort) {
                    if ($effort !== CodexCliAdapter::PROVIDER_DEFAULT_EFFORT && ! in_array($effort, $verified, true)) {
                        return 'codex_model_effort_unverified';
                    }
                }
            }
            try {
                CodexCliAdapter::assertRuntimeProfile($this->runtimeProfiles->get($profile->runtimeProfileId));
            } catch (AgentExecutionException|ConfigurationException) {
                return 'codex_runtime_profile_unsupported';
            }
        }

        return null;
    }

    /** One `--version` probe under the control policy; provider output is never echoed. */
    private function versionEvidence(CodexCliConfiguration $configuration): ?string
    {
        $processes = $this->processes ?? app(ControlProcessRunner::class);
        $result = $processes->run(new ProcessRequest(
            [$configuration->binary, '--version'],
            storage_path(),
            ['PATH'],
            [],
            new RedactionContext('doctor', null, 'codex-cli-version'),
            timeoutSeconds: self::VERSION_PROBE_TIMEOUT_SECONDS,
        ));
        if (! $result->succeeded()) {
            return 'codex_version_probe_failed';
        }

        return hash_equals($configuration->expectedVersionLine(), trim($result->output)) ? null : 'codex_version_drift';
    }

    /**
     * The protection evidence a version line cannot give: `codex features
     * list` renders the effective feature state of the pinned binary under
     * exactly the overrides of a turn. It starts no turn. A switch the pin no
     * longer offers, or a feature it reports as enabled without the approved
     * surface naming it — an extension without a switch included — locks this
     * provider by name.
     */
    private function featureSurfaceEvidence(CodexCliConfiguration $configuration): ?string
    {
        $command = [$configuration->binary, 'features', 'list'];
        foreach (CodexCliAdapter::DISABLED_FEATURES as $feature) {
            $command[] = '--disable';
            $command[] = $feature;
        }
        $processes = $this->processes ?? app(ControlProcessRunner::class);
        $result = $processes->run(new ProcessRequest(
            $command,
            storage_path(),
            ['PATH'],
            [],
            new RedactionContext('doctor', null, 'codex-cli-features'),
            timeoutSeconds: self::VERSION_PROBE_TIMEOUT_SECONDS,
        ));
        if (! $result->succeeded()) {
            return 'codex_feature_probe_failed';
        }

        try {
            [$enabled, $disabled] = CodexCliAdapter::featureStates($result->output, app(Redactor::class));
        } catch (AgentExecutionException) {
            return 'codex_feature_probe_invalid';
        }
        foreach (CodexCliAdapter::DISABLED_FEATURES as $feature) {
            if (! in_array($feature, $enabled, true) && ! in_array($feature, $disabled, true)) {
                return 'codex_feature_switch_missing';
            }
        }

        return $enabled === CodexCliAdapter::PERMITTED_ENABLED_FEATURES ? null : 'codex_extension_unapproved';
    }

    private function modelSummary(): string
    {
        $lines = [];
        foreach (CodexCliAdapter::VERIFIED_MODELS as $model => $efforts) {
            $lines[] = $model.' ('.implode('/', $efforts).')';
        }

        return implode(' | ', $lines);
    }

    private function profileSummary(): string
    {
        $lines = [];
        foreach ($this->codexProfiles() as $profile) {
            $lines[] = sprintf(
                '%s: Rollen %s; Modelle %s; Efforts %s; Runtimeprofil %s',
                $profile->id,
                implode('/', array_map(static fn (AgentRole $role): string => $role->value, $profile->roles)),
                implode('/', $profile->models),
                implode('/', $profile->efforts),
                $profile->runtimeProfileId,
            );
        }

        return $lines === [] ? 'kein Profil mit Alias codex_cli' : implode(' | ', $lines);
    }

    /** @return list<AgentProfile> */
    private function codexProfiles(): array
    {
        return array_values(array_filter(
            $this->profiles->all(),
            static fn (AgentProfile $profile): bool => $profile->providerProfileAlias === CodexCliAdapter::PROVIDER_ALIAS,
        ));
    }
}
