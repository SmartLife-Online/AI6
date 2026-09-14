<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GrokCliConfiguration;
use App\AI6\Agents\InvalidAgentResponse;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsGrokHome;
use Tests\TestCase;

final class GrokCliAdapterTest extends TestCase
{
    use BuildsGrokHome;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createGrokFixture();
    }

    protected function tearDown(): void
    {
        $this->destroyGrokFixture();
        parent::tearDown();
    }

    public function test_exact_prompt_file_transport_uses_fresh_private_sessions_and_central_validation(): void
    {
        $adapter = $this->grokAdapter();
        $context = $this->grokContext("Prüfe.\n--yolo --model foreign\n".str_repeat('ä', 10000));
        $home = $this->grokHome($context);
        self::assertArrayNotHasKey('GROK_TOOL_SEARCH', GrokCliConfiguration::environment($home));
        foreach (['agent', 'control'] as $policy) {
            self::assertNotContains('GROK_TOOL_SEARCH', config('ai6.process.policies.'.$policy.'.environment_allowlist'));
        }
        self::assertSame($home->resultDirectory.'/grok-sessions', readlink($home->home.'/sessions'));
        $settings = file_get_contents($home->home.'/config.toml');
        $answer = $adapter->turn($context, $home, static function (): void {});
        app(AgentResultValidator::class)->validate($answer->bytes, $context, new RedactionContext('test', null, 'grok'));
        self::assertSame(['input_tokens' => 12, 'output_tokens' => 0, 'num_turns' => 2], $answer->usage);
        $seen = json_decode((string) file_get_contents($home->resultDirectory.'/observation.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $adapter->prompt($context)), $seen['prompt_sha256']);
        $expected = ['--no-auto-update', '--no-memory', '--no-subagents', '--no-plan', '--disable-web-search',
            '--max-turns', '16', '--verbatim', '--tools', 'read_file,list_dir,grep', '--disallowed-tools', 'search_tool,use_tool,Agent',
            '--permission-mode', 'dontAsk', '--sandbox', 'ai6-review', '--cwd', $home->workspace,
            '--output-format', 'streaming-messages-json', '--prompt-file', $home->resultDirectory.'/grok/prompt.txt'];
        self::assertSame($expected, array_slice($adapter->lastCommand, 1));
        self::assertSame($expected, $seen['argv']);
        self::assertContains('--prompt-file', $seen['argv']);
        self::assertContains('streaming-messages-json', $seen['argv']);
        foreach (['--yolo', '--resume', '--continue', '--model'] as $argument) {
            self::assertNotContains($argument, $seen['argv']);
        }
        self::assertSame('present', $seen['env']['XAI_API_KEY']);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'GITHUB_TOKEN', 'AI6_GIT_SSH_KEY', 'CODEX_HOME'] as $name) {
            self::assertSame('missing', $seen['env'][$name]);
        }
        self::assertSame($settings, file_get_contents($home->home.'/config.toml'));
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/grok-sessions');
        self::assertSame('Original', file_get_contents($home->workspace.'/example.txt'));
        $this->expectExceptionMessage('agent_grok_invocation_not_fresh');
        $adapter->turn($context, $home, static function (): void {});
    }

    /** @return list<array{string, string}> */
    public static function surfaceDrifts(): array
    {
        return [['missing_init', 'agent_grok_init_invalid'], ['late_init', 'agent_grok_init_invalid'],
            ['multiple_init', 'agent_grok_init_invalid'], ['init_tools', 'agent_grok_init_surface_drift'],
            ['init_mcp_servers', 'agent_grok_init_surface_drift'], ['init_skills', 'agent_grok_init_surface_drift'],
            ['init_permissionMode', 'agent_grok_init_surface_drift'], ['init_cwd', 'agent_grok_init_surface_drift'],
            ['web_search', 'agent_grok_web_search_unbound'], ['missing_web_usage', 'agent_grok_web_search_unbound'],
            ['max_turns', 'agent_grok_max_turns_exceeded']];
    }

    #[DataProvider('surfaceDrifts')]
    public function test_effective_surface_drift_discards_answer_with_usage(string $scenario, string $reason): void
    {
        $context = $this->grokContext();
        try {
            $this->grokAdapter($scenario)->turn($context, $this->grokHome($context), static function (): void {});
            self::fail('Expected surface refusal.');
        } catch (InvalidAgentResponse $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertSame(12, $exception->reportedUsage?->usage['input_tokens']);
            self::assertSame('', $exception->reportedUsage->bytes);
        }
    }

    public function test_cleanup_failure_preserves_primary_exception_and_reported_usage(): void
    {
        $messages = [];
        Log::listen(static function (MessageLogged $event) use (&$messages): void {
            $messages[] = $event->message;
        });
        $context = $this->grokContext();
        try {
            $this->grokAdapter('cleanup_invalid')->turn($context, $this->grokHome($context), static function (): void {});
            self::fail('Expected invalid response.');
        } catch (InvalidAgentResponse $exception) {
            self::assertSame('agent_response_invalid', $exception->reason);
            self::assertSame(12, $exception->reportedUsage?->usage['input_tokens']);
        }
        self::assertContains('agent_grok_session_cleanup_failed', $messages);
    }

    public function test_cleanup_failure_without_primary_exception_is_a_provider_error(): void
    {
        $context = $this->grokContext();
        $this->expectExceptionMessage('agent_grok_session_cleanup_failed');
        $this->grokAdapter('cleanup_success')->turn($context, $this->grokHome($context), static function (): void {});
    }

    /** @return list<array{string}> */
    public static function brokenStreams(): array
    {
        return [['empty'], ['missing_answer'], ['invalid_json'], ['multiple'], ['multiple_missing_usage'], ['truncated'], ['invalid_utf8']];
    }

    public function test_explicit_model_is_bound_to_init_while_provider_default_remains_native(): void
    {
        $default = $this->grokContext();
        $this->grokAdapter('init_model')->turn($default, $this->grokHome($default), static function (): void {});
        $this->selectedGrokModel = 'fixture-model';
        config(['ai6.agent_profiles.grok-cli-review.models' => ['fixture-model']]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $context = $this->grokContext();
        $this->grokAdapter()->turn($context, $this->grokHome($context), static function (): void {});
        try {
            $this->grokAdapter('init_model')->turn($context, $this->grokHome($context), static function (): void {});
            self::fail('Expected model drift.');
        } catch (InvalidAgentResponse $exception) {
            self::assertSame('agent_grok_init_surface_drift', $exception->reason);
            self::assertSame(2, $exception->reportedUsage?->usage['num_turns']);
        }
    }

    public function test_malformed_turn_count_is_not_stored_as_reported_usage(): void
    {
        $context = $this->grokContext();
        try {
            $this->grokAdapter('invalid_num_turns')->turn($context, $this->grokHome($context), static function (): void {});
            self::fail('Expected invalid count.');
        } catch (InvalidAgentResponse $exception) {
            self::assertSame('agent_response_invalid', $exception->reason);
            self::assertArrayNotHasKey('num_turns', $exception->reportedUsage->usage ?? []);
        }
    }

    #[DataProvider('brokenStreams')]
    public function test_invalid_stream_preserves_reported_usage_and_cleans_sessions(string $scenario): void
    {
        $adapter = $this->grokAdapter($scenario);
        $context = $this->grokContext();
        $home = $this->grokHome($context);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected invalid_json.');
        } catch (InvalidAgentResponse $exception) {
            if ($scenario !== 'empty') {
                self::assertSame(12, $exception->reportedUsage?->usage['input_tokens']);
            }
        }
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/grok-sessions');
    }

    public function test_verifier_is_a_new_invocation_and_foreign_finding_is_rejected_centrally(): void
    {
        $context = $this->grokContext(role: AgentRole::FINDING_VERIFICATION);
        $answer = $this->grokAdapter()->turn($context, $this->grokHome($context), static function (): void {});
        app(AgentResultValidator::class)->validate($answer->bytes, $context, new RedactionContext('test', null, 'grok-verifier'));
        $foreign = $this->grokAdapter('foreign_finding')->turn($context, $this->grokHome($context), static function (): void {});
        $this->expectException(AgentResultValidationException::class);
        app(AgentResultValidator::class)->validate($foreign->bytes, $context, new RedactionContext('test', null, 'grok-verifier'));
    }

    public function test_prompt_limit_counts_wrapper_and_multibyte_bytes_before_any_transfer(): void
    {
        $adapter = $this->grokAdapter();
        $length = app(AgentInputLimits::class)->maxPromptInputBytes;
        $payloadLength = $length - strlen($adapter->prompt($this->grokContext('x'))) + 1;
        $payload = str_repeat('ä', intdiv($payloadLength, 2)).str_repeat('x', $payloadLength % 2);
        $context = $this->grokContext($payload);
        self::assertSame($length, strlen($adapter->prompt($context)));
        $accepted = $this->grokHome($context);
        $adapter->turn($context, $accepted, static function (): void {});
        $seen = json_decode((string) file_get_contents($accepted->resultDirectory.'/observation.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($length, $seen['prompt_bytes']);
        self::assertSame(hash('sha256', $adapter->prompt($context)), $seen['prompt_sha256']);
        $over = $this->grokContext($payload.'x');
        $home = $this->grokHome($over);
        try {
            $adapter->turn($over, $home, static function (): void {});
            self::fail('Expected input limit.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_prompt_input_limit_exceeded', $exception->reason);
        }
        self::assertFileDoesNotExist($home->resultDirectory.'/grok/started');
        self::assertSame([], $adapter->lastCommand);
    }

    /** @return list<array{string, string}> */
    public static function unboundInputs(): array
    {
        return [['workspace/AGENTS.md', 'agent_grok_snapshot_drift'],
            ['workspace/.mcp.json', 'agent_grok_discovery_unbound'],
            ['workspace/CLAUDE.local.md', 'agent_grok_discovery_unbound'],
            ['workspace/Agents.md', 'agent_grok_discovery_unbound'],
            ['workspace/Claude.md', 'agent_grok_discovery_unbound'],
            ['workspace/sub/AGENT.md', 'agent_grok_discovery_unbound'],
            ['home/config.toml', 'agent_grok_settings_unbound'],
            ['home/sandbox.toml', 'agent_grok_settings_unbound'],
            ['home/foreign-history', 'agent_grok_home_config_unapproved']];
    }

    #[DataProvider('unboundInputs')]
    public function test_changed_inputs_fail_before_probe_or_prompt_transfer(string $relative, string $reason): void
    {
        $context = $this->grokContext();
        $home = $this->grokHome($context);
        $path = $home->root.'/'.$relative;
        // Deliberately open the fixture boundary to prove detection of unbound input.
        chmod($home->workspace, 0700);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        chmod(dirname($path), 0700);
        if (is_file($path)) {
            chmod($path, 0600);
        }
        file_put_contents($path, 'Unapproved bait');
        $adapter = $this->grokAdapter();
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected input refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertFileDoesNotExist($home->resultDirectory.'/grok/started');
        self::assertSame([], $adapter->lastCommand);
    }

    /** @return list<array{string, string}> */
    public static function probes(): array
    {
        return [['version_drift', 'agent_grok_version_drift'], ['enabled_skill', 'agent_grok_extension_unapproved'], ['foreign_extension', 'agent_grok_extension_unapproved']];
    }

    #[DataProvider('probes')]
    public function test_native_surface_drift_prevents_a_turn(string $scenario, string $reason): void
    {
        $adapter = $this->grokAdapter($scenario);
        $context = $this->grokContext();
        $home = $this->grokHome($context);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertFileDoesNotExist($home->resultDirectory.'/observation.json');
        self::assertSame([], $adapter->lastCommand);
    }

    public function test_role_and_evidence_refusals_start_no_process(): void
    {
        foreach ([AgentRole::IMPLEMENTATION, AgentRole::SECURITY_REVIEW] as $role) {
            $context = $this->grokContext(role: $role);
            $adapter = $this->grokAdapter();
            try {
                $adapter->turn($context, $this->grokHome($context), static function (): void {});
                self::fail('Expected role refusal.');
            } catch (AgentExecutionException $exception) {
                self::assertSame('agent_grok_role_unsupported', $exception->reason);
            }
            self::assertSame([], $adapter->lastCommand);
        }
        $context = $this->grokContext();
        $this->expectExceptionMessage('agent_grok_capability_unproven');
        $this->grokAdapter(evidence: false)->turn($context, $this->grokHome($context), static function (): void {});
    }

    public function test_retargeted_session_link_is_refused_without_touching_its_target(): void
    {
        $context = $this->grokContext();
        $home = $this->grokHome($context);
        chmod($home->home, 0700);
        unlink($home->home.'/sessions');
        symlink($this->root.'/export', $home->home.'/sessions');
        try {
            $this->grokAdapter()->turn($context, $home, static function (): void {});
            self::fail('Expected target refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_grok_session_target_unbound', $exception->reason);
        }
        $this->grokManager()->destroy($home);
        array_pop($this->grokHomes);
        self::assertSame('Original', file_get_contents($this->root.'/export/example.txt'));
    }

    public function test_exit_timeout_and_cancel_never_accept_final_looking_output_and_stop_writes(): void
    {
        foreach (['exit_failure', 'timeout', 'cancel'] as $mode) {
            config(['ai6.process.policies.agent.timeout_seconds' => $mode === 'timeout' ? 1 : 60]);
            $adapter = $this->grokAdapter($mode === 'cancel' ? 'timeout' : $mode);
            $context = $this->grokContext();
            $home = $this->grokHome($context);
            try {
                $adapter->turn($context, $home, static function () use ($mode, $home): void {
                    if ($mode === 'cancel' && is_file($home->resultDirectory.'/pulse')) {
                        throw new AgentExecutionException('agent_execution_terminal');
                    }
                });
                self::fail('Expected process refusal.');
            } catch (AgentExecutionException $exception) {
                self::assertSame(match ($mode) {
                    'timeout' => 'agent_process_timed_out', 'cancel' => 'agent_execution_terminal', default => 'agent_process_failed'
                }, $exception->reason);
                if ($mode === 'exit_failure') {
                    self::assertSame(9, $exception->exitCode);
                    self::assertStringNotContainsString('synthetic-diagnostic-secret', (string) $exception);
                }
            }
            self::assertDirectoryDoesNotExist($home->resultDirectory.'/grok-sessions');
            if ($mode !== 'exit_failure') {
                $last = file_get_contents($home->resultDirectory.'/pulse');
                usleep(100000);
                self::assertSame($last, file_get_contents($home->resultDirectory.'/pulse'));
            }
        }
    }
}
