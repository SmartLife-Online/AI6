<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsCopilotHome;
use Tests\Fixtures\Agents\FakeCopilotBinary;
use Tests\TestCase;

final class GitHubCopilotCliConfigurationTest extends TestCase
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

    public function test_binary_pin_model_effort_and_runtime_are_checked_before_a_process(): void
    {
        $binary = FakeCopilotBinary::create($this->wrappers);
        $context = $this->copilotContext();
        foreach ([['', '1.0.83', 'gpt-5.4', 'provider_default', 'agent_copilot_binary_missing'],
            ['', '1.0.83', 'claude-sonnet-4.6', 'provider_default', 'agent_copilot_binary_missing'],
            [$binary, '', 'claude-sonnet-4.6', 'provider_default', 'agent_copilot_pin_missing'],
            [$binary, '1.0.84', 'claude-sonnet-4.6', 'provider_default', 'agent_copilot_transport_unsupported'],
            [$binary, '', 'gpt-5.4', 'provider_default', 'agent_copilot_pin_missing'],
            [$binary, '1.0.84', 'gpt-5.4', 'provider_default', 'agent_copilot_transport_unsupported'],
            [$binary, '1.0.83', 'provider_default', 'provider_default', 'agent_copilot_selection_unbound'],
            [$binary, '1.0.83', 'gpt-5.4', '--allow-all', 'agent_copilot_selection_unsupported']] as [$path, $pin, $model, $effort, $reason]) {
            $adapter = new GitHubCopilotCliAdapter(new GitHubCopilotCliConfiguration($path, $pin), app(AgentInputLimits::class), app(Redactor::class), app(RestrictedJsonDecoder::class), app(AgentProfileRegistry::class), app(CanonicalJson::class));
            try {
                $adapter->assertSelection($context->runtimeProfile, $context->role, $model, $effort, false);
                self::fail('Expected static refusal.');
            } catch (AgentExecutionException $exception) {
                self::assertSame($reason, $exception->reason);
            }
            self::assertSame([], $adapter->lastCommand);
        }
        $runtime = $context->runtimeProfile;
        $this->expectExceptionMessage('agent_copilot_runtime_unsupported');
        GitHubCopilotCliAdapter::assertRuntimeProfile(new ProviderRuntimeProfile($runtime->id, $runtime->version, ['allow_all' => true], $runtime->permissions, $runtime->extensions, $runtime->hash));
    }

    public function test_finding_verification_requires_both_explicit_server_role_and_separate_evidence(): void
    {
        $context = $this->copilotContext();
        config(['ai6.agent_profiles.copilot-cli-review.roles' => ['quality_review', 'finding_verification']]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $adapter = $this->copilotAdapter();
        $adapter->assertSelection($context->runtimeProfile, AgentRole::FINDING_VERIFICATION, $context->model, $context->effort, false);
        $this->expectExceptionMessage('agent_copilot_capability_unproven');
        $adapter->assertSelection($context->runtimeProfile, AgentRole::FINDING_VERIFICATION, $context->model, $context->effort);
    }

    public function test_model_identifiers_come_only_from_the_registry_and_exact_evidence(): void
    {
        $context = $this->copilotContext();
        $model = 'server-configured-model';
        config(['ai6.agent_profiles.copilot-cli-review.models' => [$model]]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $binary = FakeCopilotBinary::create($this->wrappers);
        $configuration = new GitHubCopilotCliConfiguration($binary, '1.0.83');
        foreach ([false, true] as $withEvidence) {
            $bound = new GitHubCopilotCliConfiguration($binary, '1.0.83', $withEvidence
                ? [$configuration->evidenceKey($context->runtimeProfile, $context->role, $model, $context->effort)] : []);
            $adapter = new GitHubCopilotCliAdapter($bound, app(AgentInputLimits::class), app(Redactor::class), app(RestrictedJsonDecoder::class), app(AgentProfileRegistry::class), app(CanonicalJson::class));
            try {
                $adapter->assertSelection($context->runtimeProfile, $context->role, $model, $context->effort);
                self::assertTrue($withEvidence, 'A registered model still requires exact capability evidence.');
            } catch (AgentExecutionException $exception) {
                self::assertFalse($withEvidence, 'A server-configured model with exact evidence must be accepted.');
                self::assertSame('agent_copilot_capability_unproven', $exception->reason);
            }
            self::assertSame([], $adapter->lastCommand);
        }
    }

    /** @return list<array{string, mixed}> */
    public static function malformed(): array
    {
        return [['binary', ['bad']], ['binary', "bad\0path"], ['pinned_version', '--free option'], ['capability_evidence', 'free'], ['capability_evidence', ['not-a-hash']]];
    }

    public function test_instance_environment_filters_only_empty_evidence_entries(): void
    {
        $previous = getenv('AI6_COPILOT_CAPABILITY_EVIDENCE');
        try {
            foreach (['' => [], ' , , ' => [], ' '.str_repeat('a', 64).',, '.str_repeat('b', 64).', ' => [str_repeat('a', 64), str_repeat('b', 64)]] as $value => $expected) {
                putenv('AI6_COPILOT_CAPABILITY_EVIDENCE='.$value);
                $configured = require base_path('config/ai6.php');
                config(['ai6.copilot' => $configured['copilot']]);
                self::assertSame($expected, GitHubCopilotCliConfiguration::fromConfiguredValues()->capabilityEvidence);
            }
            putenv('AI6_COPILOT_CAPABILITY_EVIDENCE= ,invalid, ');
            $configured = require base_path('config/ai6.php');
            config(['ai6.copilot' => $configured['copilot']]);
            $this->expectException(ConfigurationException::class);
            GitHubCopilotCliConfiguration::fromConfiguredValues();
        } finally {
            putenv($previous === false ? 'AI6_COPILOT_CAPABILITY_EVIDENCE' : 'AI6_COPILOT_CAPABILITY_EVIDENCE='.$previous);
        }
    }

    #[DataProvider('malformed')]
    public function test_invalid_instance_values_fail_without_echoing_the_value(string $key, mixed $value): void
    {
        config(['ai6.copilot.'.$key => $value]);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration key ai6.copilot');
        GitHubCopilotCliConfiguration::fromConfiguredValues();
    }
}
