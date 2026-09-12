<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Doctor\GitHubCopilotCliDoctorCheck;
use Tests\Fixtures\Agents\BuildsCopilotHome;
use Tests\Fixtures\Agents\FakeCopilotBinary;
use Tests\TestCase;

final class GitHubCopilotCliDoctorCheckTest extends TestCase
{
    use BuildsCopilotHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCopilotFixture();
    }

    protected function tearDown(): void
    {
        $this->destroyCopilotFixture();
        parent::tearDown();
    }

    public function test_unconfigured_provider_does_not_break_other_doctor_checks(): void
    {
        config(['ai6.copilot.binary' => '', 'ai6.copilot.pinned_version' => '']);
        $result = (new GitHubCopilotCliDoctorCheck)->run();
        self::assertTrue($result->passed);
        self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz']);
    }

    public function test_native_probe_does_not_replace_bound_runtime_evidence(): void
    {
        $binary = FakeCopilotBinary::create($this->wrappers);
        config(['ai6.copilot.binary' => $binary, 'ai6.copilot.pinned_version' => '1.0.83']);
        $check = new GitHubCopilotCliDoctorCheck;
        $result = $check->run();
        self::assertFalse($result->passed, json_encode($result->details));
        self::assertStringContainsString('Version und deaktivierte Erweiterungsoberfläche geprüft', $result->details['Reale CLI-Evidenz']);
        self::assertStringContainsString('agent_copilot_capability_unproven', implode(' ', $result->details));
        $configuration = GitHubCopilotCliConfiguration::fromConfiguredValues();
        config(['ai6.copilot.capability_evidence' => [$configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1'), AgentRole::QUALITY_REVIEW, 'gpt-5.4', 'provider_default')]]);
        $partial = $check->run();
        self::assertFalse($partial->passed);
        self::assertStringContainsString('gebundener Laufzeitnachweis konfiguriert', $partial->details['copilot-cli-review / quality_review / gpt-5.4 / provider_default']);
        self::assertSame('OK', $partial->details['copilot-claude-sonnet-review / quality_review / claude-sonnet-4.6 / provider_default statisch']);
        self::assertSame('gesperrt: agent_copilot_capability_unproven', $partial->details['copilot-claude-sonnet-review / quality_review / claude-sonnet-4.6 / provider_default']);
        config(['ai6.copilot.capability_evidence' => [...config('ai6.copilot.capability_evidence'),
            $configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1'), AgentRole::QUALITY_REVIEW, 'claude-sonnet-4.6', 'provider_default')]]);
        self::assertTrue($check->run()->passed);
        // A different binary, even with the same claimed version, invalidates the prior binding.
        config(['ai6.copilot.binary' => FakeCopilotBinary::create($this->wrappers, 'version_drift')]);
        $drift = $check->run();
        self::assertFalse($drift->passed);
        self::assertStringContainsString('agent_copilot_version_drift', implode(' ', $drift->details));
        self::assertStringNotContainsString('1.0.84', implode(' ', $drift->details));
    }
}
