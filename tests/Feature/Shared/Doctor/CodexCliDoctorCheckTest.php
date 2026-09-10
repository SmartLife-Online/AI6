<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
use App\AI6\Shared\Process\ControlProcessRunner;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Agents\FakeCodexBinary;
use Tests\TestCase;

/** TC-03: the capability doctor of the pinned Codex transport. */
final class CodexCliDoctorCheckTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', base_path('storage/framework/testing')).'/ai6-codex-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_an_instance_without_binary_and_pin_is_not_set_up_and_locks_only_the_codex_profiles(): void
    {
        $result = $this->check(new CodexCliConfiguration($this->root.'/absent', '', FakeCodexBinary::sandboxProof()))->run();
        self::assertTrue($result->passed);
        self::assertSame('nicht eingerichtet; Profile von codex_cli gesperrt', $result->details['Zustand']);
        self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz']);
        self::assertInstanceOf(FakeAgentAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'fake']));
        self::assertSame('fake', $this->app->make(AgentProfileRegistry::class)->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium')->profile->id);
    }

    public function test_static_failures_are_named_and_never_probe_the_binary(): void
    {
        $binary = FakeCodexBinary::create($this->root);
        $cases = [
            'codex_binary_missing' => [new CodexCliConfiguration($this->root.'/absent', FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), null, null],
            'codex_pin_missing' => [new CodexCliConfiguration($binary, '', FakeCodexBinary::sandboxProof()), null, null],
            'codex_transport_unverified' => [new CodexCliConfiguration($binary, '9.9.9', FakeCodexBinary::sandboxProof()), null, null],
            'codex_profile_roles_invalid' => [new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), ['roles' => ['implementation', 'quality_review', 'security_review']], null],
            'codex_model_unsupported' => [new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), ['models' => ['provider_default']], null],
            // A model the pinned catalog does not carry, and an effort the carried model does not offer.
            'codex_model_unverified' => [new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), ['models' => ['gpt-5.6-terra']], null],
            'codex_model_effort_unverified' => [new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), ['efforts' => ['low', 'ultra']], null],
            'codex_runtime_profile_unsupported' => [new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()), null, ['permissions' => ['network' => true, 'workspace' => 'read_only']]],
        ];
        foreach ($cases as $reason => [$configuration, $profileOverride, $runtimeOverride]) {
            $result = $this->check($configuration, $profileOverride, $runtimeOverride)->run();
            self::assertFalse($result->passed, $reason);
            self::assertSame($reason, $result->details['Fehler'], $reason);
            self::assertSame('FEHLER ('.$reason.')', $result->details['Statische Prüfung'], $reason);
            self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz'], $reason);
        }
    }

    public function test_real_cli_evidence_is_shown_separately_and_version_drift_fails(): void
    {
        $verified = $this->check(new CodexCliConfiguration(FakeCodexBinary::create($this->root), FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()))->run();
        self::assertTrue($verified->passed, json_encode($verified->details, JSON_UNESCAPED_UNICODE));
        self::assertSame('OK', $verified->details['Statische Prüfung']);
        self::assertSame(FakeCodexBinary::VERSION_LINE.' (gleich Pin)', $verified->details['Reale CLI-Evidenz']);
        self::assertSame('startbar', $verified->details['Profilzustand']);
        self::assertSame('verifiziert für '.FakeCodexBinary::PINNED_VERSION, $verified->details['Transportnachweis']);
        self::assertStringContainsString('codex-gpt-5.6-terra: Rollen implementation/quality_review; Modelle gpt-5.3-codex; Efforts low/medium/high/xhigh', $verified->details['Rollen und Modelle']);
        self::assertStringContainsString('gpt-5.3-codex (low/medium/high/xhigh)', $verified->details['Nachgewiesene Modelle']);
        self::assertStringContainsString('--output-schema', $verified->details['Transportflags']);
        self::assertStringContainsString('gebündelte Vendor-Skills werden nach $CODEX_HOME/skills/.system entpackt', $verified->details['Sandbox- und Discoverygrenzen']);
        self::assertSame('11 Schalter aus, 16 ohne Schalter erlaubt (codex features list, ohne Turn)', $verified->details['Schutznachweis']);
        self::assertSame('gebunden an '.FakeCodexBinary::sandboxProof().' (AI6-033/MG-01)', $verified->details['Sandbox- und Toolnetznachweis']);

        $drift = $this->check(new CodexCliConfiguration(FakeCodexBinary::create($this->root, versionLine: 'codex-cli 0.130.0'), FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()))->run();
        self::assertFalse($drift->passed);
        self::assertSame('OK', $drift->details['Statische Prüfung']);
        self::assertSame('FEHLER (codex_version_drift)', $drift->details['Reale CLI-Evidenz']);
        self::assertStringNotContainsString('0.130.0', implode(' ', $drift->details), 'Provider output is never echoed.');

        $failed = $this->check(new CodexCliConfiguration(FakeCodexBinary::create($this->root, 'version_probe_fails'), FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()))->run();
        self::assertFalse($failed->passed);
        self::assertSame('FEHLER (codex_version_probe_failed)', $failed->details['Reale CLI-Evidenz']);
    }

    /**
     * TC-03: a matching version line is not a protection proof. A binary whose
     * switch vanished, whose switch is ignored, or that enables an extension
     * the approved surface never named locks this provider — even though the
     * version and the flag surface still match.
     */
    public function test_a_matching_version_is_not_enough_without_the_feature_surface_evidence(): void
    {
        $cases = [
            ['feature_switch_missing', 'codex_feature_switch_missing'],
            ['feature_switch_ignored', 'codex_extension_unapproved'],
            ['extension_enabled', 'codex_extension_unapproved'],
            ['feature_probe_invalid', 'codex_feature_probe_invalid'],
            ['feature_probe_fails', 'codex_feature_probe_failed'],
        ];
        foreach ($cases as [$scenario, $reason]) {
            $result = $this->check(new CodexCliConfiguration(FakeCodexBinary::create($this->root, $scenario), FakeCodexBinary::PINNED_VERSION, FakeCodexBinary::sandboxProof()))->run();
            self::assertFalse($result->passed, $scenario);
            self::assertSame('OK', $result->details['Statische Prüfung'], $scenario);
            self::assertSame(FakeCodexBinary::VERSION_LINE.' (gleich Pin)', $result->details['Reale CLI-Evidenz'], $scenario);
            self::assertSame('FEHLER ('.$reason.')', $result->details['Schutznachweis'], $scenario);
            self::assertArrayNotHasKey('Profilzustand', $result->details, $scenario);
        }
    }

    /**
     * TC-03: a green feature surface is version evidence, not runtime evidence.
     * Without a sandbox and tool-network proof bound to this pin and this
     * platform the provider is never reported as startable — and there is no
     * full-access fallback that would let it run anyway.
     */
    public function test_a_green_feature_surface_without_a_bound_sandbox_proof_is_not_startable(): void
    {
        $binary = FakeCodexBinary::create($this->root);
        $platform = CodexCliConfiguration::runtimePlatform();
        $cases = [
            ['', 'codex_sandbox_unproven'],
            // Present but not binding: another pin, another platform, or a bare claim.
            ['0.130.0:'.$platform, 'codex_sandbox_proof_invalid'],
            [FakeCodexBinary::PINNED_VERSION.':'.($platform === 'linux' ? 'windows' : 'linux'), 'codex_sandbox_proof_invalid'],
        ];
        foreach ($cases as [$proof, $reason]) {
            $result = $this->check(new CodexCliConfiguration($binary, FakeCodexBinary::PINNED_VERSION, $proof))->run();
            self::assertFalse($result->passed, $reason);
            self::assertSame('OK', $result->details['Statische Prüfung'], $reason);
            self::assertStringStartsWith(FakeCodexBinary::VERSION_LINE, $result->details['Reale CLI-Evidenz'], $reason);
            self::assertStringStartsWith('11 Schalter aus', $result->details['Schutznachweis'], $reason);
            self::assertSame('FEHLER ('.$reason.')', $result->details['Sandbox- und Toolnetznachweis'], $reason);
            self::assertSame($reason, $result->details['Fehler'], $reason);
            self::assertArrayNotHasKey('Profilzustand', $result->details, $reason);
        }
    }

    public function test_a_misshapen_sandbox_proof_is_a_named_configuration_error(): void
    {
        config([
            'ai6.codex.binary' => FakeCodexBinary::create($this->root),
            'ai6.codex.pinned_version' => FakeCodexBinary::PINNED_VERSION,
            'ai6.codex.sandbox_proof' => 'proven',
        ]);
        $result = (new CodexCliDoctorCheck(
            $this->app->make(AgentProfileRegistry::class),
            $this->app->make(ProviderRuntimeProfileRegistry::class),
            $this->app->make(ControlProcessRunner::class),
        ))->run();
        self::assertFalse($result->passed);
        self::assertSame('codex_configuration_invalid', $result->details['Fehler']);
    }

    public function test_the_doctor_command_reports_the_codex_check_from_the_current_configuration(): void
    {
        config([
            'ai6.codex.binary' => FakeCodexBinary::create($this->root),
            'ai6.codex.pinned_version' => FakeCodexBinary::PINNED_VERSION,
            'ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof(),
        ]);
        $exitCode = Artisan::call('ai6:doctor');
        $output = Artisan::output();
        self::assertStringContainsString('Codex-CLI: OK', $output);
        self::assertStringContainsString('Statische Prüfung: OK', $output);
        self::assertStringContainsString('Reale CLI-Evidenz: '.FakeCodexBinary::VERSION_LINE.' (gleich Pin)', $output);
        self::assertStringContainsString('Sandbox- und Toolnetznachweis: gebunden an '.FakeCodexBinary::sandboxProof(), $output);
        self::assertStringContainsString('Profilzustand: startbar', $output);

        // The shipped instance asserts nothing, so the provider stays locked.
        config(['ai6.codex.sandbox_proof' => '']);
        Artisan::call('ai6:doctor');
        $unproven = Artisan::output();
        self::assertStringContainsString('Fehler: codex_sandbox_unproven', $unproven);
        self::assertStringNotContainsString('Profilzustand: startbar', $unproven);
        config(['ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof()]);

        config(['ai6.codex.binary' => $this->root.'/absent']);
        $failing = Artisan::call('ai6:doctor');
        $failingOutput = Artisan::output();
        self::assertSame(1, $failing);
        self::assertStringContainsString('Codex-CLI: FEHLER', $failingOutput);
        self::assertStringContainsString('Fehler: codex_binary_missing', $failingOutput);
        // The rest of the doctor is untouched by the locked provider.
        self::assertStringContainsString('SecurityPolicy: OK', $failingOutput);
        self::assertSame($exitCode === 0, str_contains($output, 'Checker-Laufzeit: OK'));
    }

    /**
     * @param  array<string, mixed>|null  $profileOverride
     * @param  array<string, mixed>|null  $runtimeOverride
     */
    private function check(CodexCliConfiguration $configuration, ?array $profileOverride = null, ?array $runtimeOverride = null): CodexCliDoctorCheck
    {
        $profiles = config('ai6.agent_profiles');
        if ($profileOverride !== null) {
            $profiles['codex-gpt-5.6-terra'] = array_replace($profiles['codex-gpt-5.6-terra'], $profileOverride);
        }
        $runtimes = config('ai6.provider_runtime_profiles');
        if ($runtimeOverride !== null) {
            $runtimes['codex-cli-v1'] = array_replace($runtimes['codex-cli-v1'], $runtimeOverride);
        }

        return new CodexCliDoctorCheck(
            AgentProfileRegistry::fromArray($profiles, new StrictEnumParser),
            ProviderRuntimeProfileRegistry::fromArray($runtimes, new StrictPositiveIntegerParser, new CanonicalJson),
            $this->app->make(ControlProcessRunner::class),
            $configuration,
        );
    }
}
