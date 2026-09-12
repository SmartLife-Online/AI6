<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\InvalidAgentResponse;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Redaction\RedactionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsCopilotHome;
use Tests\TestCase;

class GitHubCopilotCliAdapterTest extends TestCase
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

    /** @param array<string, int|float|null> $usage */
    #[DataProvider('answers')]
    public function test_stdin_turn_is_centrally_validated_and_preserves_reported_usage(string $scenario, array $usage, string $source): void
    {
        $adapter = $this->copilotAdapter($scenario);
        $context = $this->copilotContext("Prüfe.\n--allow-all --model foreign\n".str_repeat('ä', 10000));
        $home = $this->copilotHome($context);
        $pulse = 0;
        $result = $adapter->turn($context, $home, static function () use (&$pulse): void {
            $pulse++;
        });
        app(AgentResultValidator::class)->validate($result->bytes, $context, new RedactionContext('test', null, 'copilot'));
        self::assertSame($usage, $result->usage);
        self::assertSame($source, $result->usageSource);
        $observation = json_decode((string) file_get_contents($home->resultDirectory.'/copilot/observation.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(hash('sha256', $adapter->prompt($context)), $observation['prompt_sha256']);
        self::assertSame($context->model, $observation['argv'][array_search('--model', $observation['argv'], true) + 1]);
        self::assertSame(array_slice($adapter->lastCommand, 1), $observation['argv']);
        self::assertStringContainsString('/copilot-', str_replace('\\', '/', $adapter->lastCommand[0]));
        self::assertGreaterThan(0, $pulse);
        self::assertNotContains('--allow-all', $observation['argv']);
        self::assertNotContains('--resume', $observation['argv']);
        self::assertNotContains('--no-experimental', $observation['argv']);
        self::assertFalse(json_decode((string) file_get_contents($home->home.'/settings.json'), true, 32, JSON_THROW_ON_ERROR)['experimental']);
        self::assertContains('--available-tools='.GitHubCopilotCliAdapter::READ_TOOLS, $observation['argv']);
        self::assertContains('--excluded-tools='.GitHubCopilotCliAdapter::EXCLUDED_TOOLS, $observation['argv']);
        self::assertContains('--deny-tool=shell,write,url,github-mcp-server', $observation['argv']);
        self::assertNotContains('--allow-tool=read', $observation['argv']);
        self::assertSame('bash,powershell,write,edit,create,task,skill,web_fetch,web_search,store_memory,read_memories,vote_memory,delegate,read_bash,write_bash,stop_bash,sql,fetch_copilot_cli_documentation,github-mcp-server', GitHubCopilotCliAdapter::EXCLUDED_TOOLS);
        self::assertSame('present', $observation['env']['COPILOT_GITHUB_TOKEN']);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'GITHUB_TOKEN', 'GH_TOKEN', 'AI6_GIT_SSH_KEY', 'CODEX_HOME', 'XDG_CONFIG_HOME'] as $name) {
            self::assertSame('missing', $observation['env'][$name], $name);
        }
        self::assertSame(GitHubCopilotCliConfiguration::settingsBytes($context->runtimeProfile, app(CanonicalJson::class)), file_get_contents($home->home.'/settings.json'));
        self::assertSame(['.', '..'], scandir($home->home.'/session-state'));
        self::assertSame('Original', file_get_contents($home->workspace.'/example.txt'));
    }

    /** @return list<array{string, array<string, int|null>, string}> */
    public static function answers(): array
    {
        return [['success', ['premium_requests' => 0, 'api_duration_ms' => 12], 'copilot_cli_usage_file'],
            ['fenced', ['premium_requests' => 0, 'api_duration_ms' => 12], 'copilot_cli_usage_file'],
            ['null_usage', ['premium_requests' => null, 'api_duration_ms' => 0], 'copilot_cli_usage_file'], ['missing_usage', [], 'unknown']];
    }

    #[DataProvider('invalidAnswers')]
    public function test_ambiguous_or_broken_answer_is_never_repaired(string $scenario): void
    {
        $adapter = $this->copilotAdapter($scenario);
        $context = $this->copilotContext();
        $this->expectException(InvalidAgentResponse::class);
        $adapter->turn($context, $this->copilotHome($context), static function (): void {});
    }

    /** @return list<array{string}> */
    public static function invalidAnswers(): array
    {
        return [['empty'], ['invalid_json'], ['multiple'], ['prefix'], ['invalid_utf8']];
    }

    #[DataProvider('probeFailures')]
    public function test_native_probe_rejects_drift_before_a_model_turn(string $scenario, string $reason): void
    {
        $adapter = $this->copilotAdapter($scenario);
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertSame([], $adapter->lastCommand);
        self::assertFileDoesNotExist($home->resultDirectory.'/copilot/observation.json');
    }

    /** @return list<array{string, string}> */
    public static function probeFailures(): array
    {
        return [['version_drift', 'agent_copilot_version_drift'], ['enabled_skill', 'agent_copilot_extension_unapproved'], ['foreign_extension', 'agent_copilot_extension_unapproved']];
    }

    #[DataProvider('forbiddenRoles')]
    public function test_unapproved_roles_start_no_process(AgentRole $role): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext(role: $role);
        try {
            $adapter->turn($context, $this->copilotHome($context), static function (): void {});
            self::fail('Expected refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertContains($exception->reason, ['agent_copilot_role_unsupported', 'agent_copilot_selection_unbound']);
        }
        self::assertSame([], $adapter->lastCommand);
    }

    /** @return list<array{AgentRole}> */
    public static function forbiddenRoles(): array
    {
        return [[AgentRole::IMPLEMENTATION], [AgentRole::SECURITY_REVIEW], [AgentRole::FINDING_VERIFICATION]];
    }

    public function test_missing_capability_evidence_prevents_even_probe_or_partial_input(): void
    {
        $adapter = $this->copilotAdapter(evidence: false);
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_copilot_capability_unproven', $exception->reason);
        }
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/copilot');
    }

    public function test_limit_counts_exact_transferred_wrapper_and_multibyte_prompt(): void
    {
        $context = $this->copilotContext(str_repeat('ä', 524288));
        $length = strlen($this->copilotAdapter()->prompt($context));
        $limits = new AgentInputLimits(16, 262144, 1048576, 8, $length);
        $adapter = $this->copilotAdapter(limits: $limits);
        $home = $this->copilotHome($context);
        $adapter->turn($context, $home, static function (): void {});
        self::assertSame($length, strlen($adapter->lastPrompt));
        $over = $this->copilotContext(str_repeat('ä', 524288).'x');
        $overHome = $this->copilotHome($over);
        try {
            $adapter->turn($over, $overHome, static function (): void {});
            self::fail('Expected limit.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_prompt_input_limit_exceeded', $exception->reason);
        }
        self::assertDirectoryDoesNotExist($overHome->resultDirectory.'/copilot');
        self::assertSame([], $adapter->lastCommand);
    }

    #[DataProvider('discoveryBaits')]
    public function test_unapproved_discovery_and_snapshot_drift_prevent_start(string $path): void
    {
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        $target = $home->workspace.'/'.$path;
        chmod($home->workspace, 0700);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        if (is_file($target)) {
            chmod($target, 0600);
        }
        file_put_contents($target, 'untrusted');
        $adapter = $this->copilotAdapter();
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected discovery refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertContains($exception->reason, ['agent_copilot_snapshot_drift', 'agent_copilot_discovery_unbound']);
        }
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/copilot');
    }

    /** @return list<array{string}> */
    public static function discoveryBaits(): array
    {
        return [['AGENTS.md'], ['CLAUDE.md'], ['.claude/settings.json'], ['.github/copilot-instructions.md'], ['.github/skills/bait/SKILL.md'], ['.github/hooks/hook.json'], ['.git'], ['other/AGENTS.md'], ['.mcp.json']];
    }

    public function test_invocations_never_reuse_native_history(): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext();
        $first = $this->copilotHome($context);
        $second = $this->copilotHome($context);
        self::assertNotSame($first->home, $second->home);
        $adapter->turn($context, $first, static function (): void {});
        $adapter->turn($context, $second, static function (): void {});
        $this->expectExceptionMessage('agent_copilot_invocation_not_fresh');
        $adapter->turn($context, $first, static function (): void {});
    }

    public function test_result_has_no_legacy_transport(): void
    {
        $adapter = $this->copilotAdapter();
        $this->expectExceptionMessage('agent_copilot_result_requires_turn');
        $adapter->result($this->copilotContext());
    }

    public function test_exit_failure_preserves_exit_code_and_centrally_redacted_diagnostic(): void
    {
        $adapter = $this->copilotAdapter('exit_failure');
        $context = $this->copilotContext();
        try {
            $adapter->turn($context, $this->copilotHome($context), static function (): void {});
            self::fail('Expected process failure.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_process_failed', $exception->reason);
            self::assertSame(ProcessOutcome::FAILED, $exception->processOutcome);
            self::assertSame(9, $exception->exitCode);
            self::assertSame('provider failure: permission denied; token=[REDACTED:TOKEN]', $exception->diagnostic);
            self::assertStringNotContainsString('synthetic-diagnostic-secret', (string) $exception);
        }
    }

    public function test_native_state_created_during_a_writable_turn_is_refused(): void
    {
        $adapter = $this->copilotAdapter('native_state');
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        chmod($home->home.'/session-state', 0700);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected native state refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_copilot_native_state_unapproved', $exception->reason);
            self::assertSame(0, $exception->exitCode);
            self::assertSame(ProcessOutcome::SUCCEEDED, $exception->processOutcome);
        }
        self::assertFileExists($home->home.'/session-state/foreign.json');
    }

    public function test_native_state_write_is_denied_in_a_sealed_home(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Requires an unprivileged Linux identity for actual write denial.');
        }
        $adapter = $this->copilotAdapter('native_state');
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        $before = scandir($home->home.'/session-state');
        $result = $adapter->turn($context, $home, static function (): void {});
        self::assertSame($before, scandir($home->home.'/session-state'));
        self::assertFileDoesNotExist($home->home.'/session-state/foreign.json');
        app(AgentResultValidator::class)->validate($result->bytes, $context, new RedactionContext('test', null, 'copilot'));
    }

    public function test_timeout_and_cancellation_stop_the_native_process(): void
    {
        foreach (['timeout', 'cancel'] as $mode) {
            config(['ai6.process.policies.agent.timeout_seconds' => $mode === 'timeout' ? 1 : 60]);
            $adapter = $this->copilotAdapter('timeout');
            $context = $this->copilotContext();
            $home = $this->copilotHome($context);
            $pulse = $home->resultDirectory.'/copilot/pulse';
            try {
                $adapter->turn($context, $home, static function () use ($mode, $pulse): void {
                    clearstatcache(true, $pulse);
                    if ($mode === 'cancel' && is_file($pulse)) {
                        throw new AgentExecutionException('agent_execution_terminal');
                    }
                });
                self::fail('Expected terminal turn.');
            } catch (AgentExecutionException $exception) {
                self::assertSame($mode === 'timeout' ? 'agent_process_timed_out' : 'agent_execution_terminal', $exception->reason);
            }
            self::assertFileExists($pulse);
            $last = file_get_contents($pulse);
            usleep(700000);
            self::assertSame($last, file_get_contents($pulse), 'No child keeps writing after termination.');
        }
    }

    /** @return list<array{string, string}> */
    public static function nativeBaits(): array
    {
        return [['AGENTS.md', 'agent_copilot_home_config_unapproved'], ['config.json', 'agent_copilot_home_config_unapproved'],
            ['session-store.db', 'agent_copilot_home_config_unapproved'], ['session-store.db-wal', 'agent_copilot_home_config_unapproved'],
            ['session-store.db-shm', 'agent_copilot_home_config_unapproved'], ['session-state/foreign.json', 'agent_copilot_native_state_unapproved'],
            ['auth/foreign-token', 'agent_copilot_native_state_unapproved'], ['settings.json', 'agent_copilot_settings_unbound']];
    }

    #[DataProvider('nativeBaits')]
    public function test_foreign_native_home_state_is_not_resumed(string $path, string $reason): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        $target = $home->home.'/'.$path;
        chmod(dirname($target), 0700);
        if (is_file($target)) {
            chmod($target, 0600);
        }
        file_put_contents($target, 'foreign');
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected native state refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/copilot');
    }

    public function test_destroyed_home_never_starts_a_provider(): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        $this->copilotManager()->destroy($home);
        array_pop($this->copilotHomes);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected missing home.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_copilot_home_missing', $exception->reason);
        }
        self::assertSame([], $adapter->lastCommand);
    }

    public function test_standalone_parent_instructions_do_not_block_a_bound_workspace(): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        file_put_contents($this->root.'/AGENTS.md', 'Parent bait.');
        file_put_contents($this->root.'/CLAUDE.md', 'Parent bait.');
        foreach (['.claude', '.github', '.copilot'] as $directory) {
            mkdir($this->root.'/'.$directory);
            file_put_contents($this->root.'/'.$directory.'/settings.json', '{}');
        }
        $result = $adapter->turn($context, $home, static function (): void {});
        app(AgentResultValidator::class)->validate($result->bytes, $context, new RedactionContext('test', null, 'copilot'));
        self::assertNotSame([], $adapter->lastCommand);
    }

    #[DataProvider('gitAncestorKinds')]
    public function test_git_ancestor_capture_never_starts_a_provider(bool $directory): void
    {
        $adapter = $this->copilotAdapter();
        $context = $this->copilotContext();
        $home = $this->copilotHome($context);
        if ($directory) {
            mkdir($this->root.'/.git');
        } else {
            file_put_contents($this->root.'/.git', 'gitdir: elsewhere');
        }
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected parent refusal.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_copilot_parent_discovery_unbound', $exception->reason);
        }
        self::assertSame([], $adapter->lastCommand);
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/copilot');
    }

    /** @return list<array{bool}> */
    public static function gitAncestorKinds(): array
    {
        return [[true], [false]];
    }
}
