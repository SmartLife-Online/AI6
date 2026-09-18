<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\GrokCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Process\AgentProcessScope;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GrokCliConfigurationTest extends TestCase
{
    /** @return list<array{string, mixed}> */
    public static function malformed(): array
    {
        return [['binary', []], ['binary', "bad\0path"], ['pinned_version', '--free'], ['capability_evidence', 'free'], ['capability_evidence', ['bad']]];
    }

    #[DataProvider('malformed')]
    public function test_invalid_trusted_values_fail_without_echoing_them(string $key, mixed $value): void
    {
        config(['ai6.grok.'.$key => $value]);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration key ai6.grok');
        GrokCliConfiguration::fromConfiguredValues();
    }

    public function test_missing_binary_and_pin_are_valid_unconfigured_state(): void
    {
        config(['ai6.grok.binary' => '', 'ai6.grok.pinned_version' => '']);
        $configuration = GrokCliConfiguration::fromConfiguredValues();
        self::assertFalse($configuration->binaryPresent());
        self::assertSame([], $configuration->capabilityEvidence);
    }

    public function test_evidence_binds_role_model_effort_and_runtime(): void
    {
        $configuration = new GrokCliConfiguration('', '1.0.5');
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get('grok-cli-v1');
        $key = $configuration->evidenceKey($runtime, AgentRole::QUALITY_REVIEW, 'provider_default', 'provider_default');
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $key);
        self::assertNotSame($key, $configuration->evidenceKey($runtime, AgentRole::FINDING_VERIFICATION, 'provider_default', 'provider_default'));
        self::assertNotSame($key, $configuration->evidenceKey($runtime, AgentRole::QUALITY_REVIEW, 'other-model', 'provider_default'));
        self::assertNotSame($key, $configuration->evidenceKey($runtime, AgentRole::QUALITY_REVIEW, 'provider_default', 'high'));
        config(['ai6.execution_mailboxes.agent_root' => '/var/lib/ai6/other-inputs']);
        self::assertNotSame($key, $configuration->evidenceKey($runtime, AgentRole::QUALITY_REVIEW, 'provider_default', 'provider_default'));
    }

    public function test_sandbox_work_directory_is_server_fixed_and_separate_from_home_and_results(): void
    {
        $home = new ExecutionHome('/inputs/turn', '/outputs/turn', '/inputs/turn/workspace', '/inputs/turn/home',
            '/inputs/turn/instructions', '/inputs/turn/runtime/profile.json', '/private/projection/auth',
            '/outputs/turn/result', '/outputs/turn/artifacts', '/outputs/turn/patch');
        $environment = GrokCliConfiguration::environment($home);
        self::assertSame('/tmp/ai6-provider-sandbox', $environment['GROK_SANDBOX_WORK_DIR']);
        self::assertSame(AgentProcessScope::SANDBOX_WORK_DIRECTORY, $environment['GROK_SANDBOX_WORK_DIR']);
        self::assertSame($home->home, $environment['HOME']);
        self::assertSame($home->home, $environment['GROK_HOME']);
        self::assertSame($home->resultDirectory, $environment['TMPDIR']);
        self::assertArrayNotHasKey('__GROK_INSIDE_BWRAP', $environment);
    }

    public function test_sandbox_bytes_bind_staging_and_private_auth_and_runtime_subtrees(): void
    {
        config(['ai6.execution_mailboxes.agent_root' => '/var/lib/ai6/agent-executions']);
        self::assertSame("[profiles.ai6-review]\nextends = \"strict\"\nrestrict_network = true\ndeny = [\"/var/lib/ai6/agent-executions/execution-*/*/home/auth\",\"/var/lib/ai6/agent-executions/execution-*/*/runtime\",\"/run/ai6/provider-private/projection-*\",\"/run/ai6/provider-private/probe-*/inputs/*/home/auth\",\"/run/ai6/provider-private/probe-*/inputs/*/runtime\"]\n", GrokCliConfiguration::sandboxBytes());
    }

    /** @return list<array{mixed}> */
    public static function invalidSandboxRoots(): array
    {
        return [[null], [''], ['relative'], ['/var/../tmp'], ['/var/*'], ["/var\nforeign"]];
    }

    #[DataProvider('invalidSandboxRoots')]
    public function test_ambiguous_sandbox_root_is_refused(mixed $root): void
    {
        config(['ai6.execution_mailboxes.agent_root' => $root]);
        $this->expectException(ConfigurationException::class);
        GrokCliConfiguration::sandboxBytes();
    }

    /** @return list<array{string, string, string, string, string}> */
    public static function unavailableSelections(): array
    {
        return [['', '1.0.5', 'provider_default', 'provider_default', 'agent_grok_binary_missing'],
            [PHP_BINARY, '', 'provider_default', 'provider_default', 'agent_grok_pin_missing'],
            [PHP_BINARY, '1.0.6', 'provider_default', 'provider_default', 'agent_grok_transport_unsupported'],
            [PHP_BINARY, '1.0.5', 'foreign', 'provider_default', 'agent_grok_selection_unbound'],
            [PHP_BINARY, '1.0.5', 'provider_default', 'high', 'agent_grok_selection_unsupported'],
            [PHP_BINARY, '1.0.5', 'provider_default', 'provider_default', 'agent_grok_capability_unproven']];
    }

    #[DataProvider('unavailableSelections')]
    public function test_unavailable_selection_has_a_named_reason_before_process_start(string $binary, string $pin, string $model, string $effort, string $reason): void
    {
        config(['ai6.grok.binary' => $binary, 'ai6.grok.pinned_version' => $pin, 'ai6.grok.capability_evidence' => []]);
        $this->app->forgetInstance(GrokCliConfiguration::class);
        $adapter = app(GrokCliAdapter::class);
        try {
            $adapter->assertSelection(app(ProviderRuntimeProfileRegistry::class)->get('grok-cli-v1'), AgentRole::QUALITY_REVIEW, $model, $effort);
            self::fail('Expected selection refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertSame([], $adapter->lastCommand);
    }
}
