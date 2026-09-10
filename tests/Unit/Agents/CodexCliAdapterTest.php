<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeException;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\InstructionDiscovery;
use App\AI6\Agents\InstructionResolutionProfile;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\InvalidAgentResponse;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Agents\TurnContainmentBoundary;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\FakeCodexBinary;
use Tests\TestCase;

/**
 * Contract tests of the Codex transport against the deterministic fake CLI
 * (TC-02, TC-04, TC-05, TC-08, TC-09, TC-10, TC-11, TC-12, TC-13). They
 * prove the AI6 wiring: argument list, environment, home layout, prompt
 * bytes, hull extraction and process termination. The sandbox, discovery and
 * credential boundaries of the real CLI remain MG-01 evidence.
 */
final class CodexCliAdapterTest extends TestCase
{
    private string $root;

    /** The wrappers stay inside the repository (an executable location), the homes outside every Git repository. */
    private string $wrappers;

    /** @var list<ExecutionHome> */
    private array $homes = [];

    private InstructionSnapshot $snapshot;

    private ProviderRuntimeProfile $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/ai6-codex-'.bin2hex(random_bytes(6));
        $this->wrappers = str_replace('\\', '/', base_path('storage/framework/testing')).'/ai6-codex-wrappers-'.bin2hex(random_bytes(6));
        foreach (['export/app', 'inputs', 'outputs'] as $directory) {
            self::assertTrue(mkdir($this->root.'/'.$directory, 0700, true));
        }
        self::assertTrue(mkdir($this->wrappers, 0700, true));
        file_put_contents($this->root.'/export/app/Example.php', "<?php\n\n// original\n");
        file_put_contents($this->root.'/export/AGENTS.md', "repository instructions\n");
        file_put_contents($this->root.'/auth.json', '{"OPENAI_API_KEY":"test-projection"}');
        // An earlier test may have unset a variable with putenv() while PHPUnit's
        // $_ENV copy still carries it; the child-environment proof below needs the
        // parent process to actually hold the forbidden values it must withhold.
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'SESSION_DRIVER'] as $name) {
            if (getenv($name) === false && is_string($_ENV[$name] ?? null)) {
                putenv($name.'='.$_ENV[$name]);
            }
        }
        $entry = new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), "bound instructions\n", []);
        $this->snapshot = new InstructionSnapshot('codex_cli', [$entry], str_repeat('b', 64));
        $this->runtime = $this->app->make(ProviderRuntimeProfileRegistry::class)->get('codex-cli-v1');
    }

    protected function tearDown(): void
    {
        foreach ($this->homes as $home) {
            try {
                $this->manager()->destroy($home);
            } catch (ExecutionHomeException) {
                // The directory sweep below removes what a test left behind on purpose.
            }
        }
        $this->remove($this->root);
        $this->remove($this->wrappers);
        parent::tearDown();
    }

    /** TC-02: argument list and environment follow the sealed selection and the bound runtime profile. */
    #[DataProvider('roles')]
    public function test_a_turn_derives_command_and_environment_from_the_sealed_selection(AgentRole $role): void
    {
        $binary = FakeCodexBinary::create($this->wrappers);
        $adapter = $this->adapter(binary: $binary);
        $context = $this->context($role);
        $home = $this->home($role, $context);

        $answer = $adapter->turn($context, $home, static function (): void {});

        $workspace = str_replace('\\', '/', (string) realpath($home->workspace));
        $command = $adapter->lastCommand;
        self::assertSame($binary, $command[0]);
        self::assertSame('exec', $command[1]);
        foreach (['--json', '--ephemeral', '--ignore-user-config', '--ignore-rules', '--skip-git-repo-check'] as $flag) {
            self::assertContains($flag, $command);
        }
        self::assertSame('never', $this->optionValue($command, '--color'));
        self::assertSame($role === AgentRole::IMPLEMENTATION ? 'workspace-write' : 'read-only', $this->optionValue($command, '--sandbox'));
        self::assertSame($workspace, $this->optionValue($command, '--cd'));
        self::assertSame('gpt-5.3-codex', $this->optionValue($command, '--model'));
        self::assertSame([
            'model_reasoning_effort="high"',
            'approval_policy="never"',
            'sandbox_workspace_write.network_access=false',
            'sandbox_workspace_write.exclude_slash_tmp=true',
            'history.persistence="none"',
            'project_doc_max_bytes=1048576',
            'project_doc_fallback_filenames=[]',
            'mcp_servers={}',
        ], $this->optionValues($command, '--config'));
        self::assertSame(CodexCliAdapter::DISABLED_FEATURES, $this->optionValues($command, '--disable'));
        self::assertSame($home->resultDirectory.'/codex/output-schema.json', $this->optionValue($command, '--output-schema'));
        self::assertSame(['--', '-'], array_slice($command, -2), 'The prompt travels over standard input, never as an argument.');
        self::assertStringStartsWith($this->prompt(), $adapter->lastPrompt);
        foreach (['resume', '--last', 'danger-full-access', '--full-auto', '--dangerously-bypass-approvals-and-sandbox', '--add-dir'] as $forbidden) {
            self::assertNotContains($forbidden, $command);
        }
        self::assertSame([
            'HOME' => $home->home, 'CODEX_HOME' => $home->authDirectory, 'TMPDIR' => $home->resultDirectory.'/codex/tmp',
            'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8',
        ], $adapter->lastEnvironment);

        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        self::assertSame(array_slice($command, 1), $observation['argv'], 'The process received exactly the derived options.');
        self::assertSame($adapter->lastPrompt, $observation['options']['prompt'], 'Standard input carries the exact prompt bytes.');
        self::assertSame(strlen($adapter->lastPrompt), $observation['prompt_bytes']);
        self::assertSame($workspace, $observation['cwd']);
        foreach (['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY', 'AI6_GIT_KNOWN_HOSTS', 'SESSION_DRIVER', 'OPENAI_API_KEY', 'AI6_RUNTIME_PROFILE', 'AI6_AUTH_FILE', 'XDG_CONFIG_HOME'] as $name) {
            self::assertSame('missing', $observation['env'][$name], $name);
        }
        foreach (['HOME', 'CODEX_HOME', 'TMPDIR', 'LANG', 'LC_ALL'] as $name) {
            self::assertSame('present', $observation['env'][$name], $name);
        }
        self::assertSame('readable', $observation['auth']);
        self::assertSame(['auth.json'], $observation['codex_home_entries']);
        self::assertSame(['input_tokens' => 120, 'cached_input_tokens' => 20, 'output_tokens' => 45], $answer->usage);
        self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->usageSource);

        $validated = $this->app->make(AgentResultValidator::class)->validate($answer->bytes, $context, new RedactionContext('test', null, 'codex-answer'));
        self::assertSame($role === AgentRole::IMPLEMENTATION ? AgentResultStatus::COMPLETED : AgentResultStatus::NOTHING_TO_FIX, $validated->status);
        if ($role === AgentRole::IMPLEMENTATION) {
            self::assertSame(['app/Example.php'], $validated->changedPaths);
            self::assertSame("<?php\n\n// fake-codex-change\n", file_get_contents($home->workspace.'/app/Example.php'));
        } else {
            self::assertSame(['AC-01', 'AC-02'], array_map(static fn ($entry) => $entry->criterionId, $validated->criterionCoverage));
            self::assertSame('denied', $observation['review_write']['existing'], 'A review turn cannot write an existing workspace file.');
            if (DIRECTORY_SEPARATOR === '/') {
                self::assertSame('denied', $observation['review_write']['new'], 'A review turn cannot create a workspace file (POSIX mode).');
            }
        }
    }

    /** @return array<string, array{AgentRole}> */
    public static function roles(): array
    {
        return ['implementation' => [AgentRole::IMPLEMENTATION], 'quality review' => [AgentRole::QUALITY_REVIEW]];
    }

    /** TC-02: no free option from project or UI and no option syntax in the prompt changes binary or sandbox. */
    public function test_free_options_are_refused_and_option_like_prompt_text_stays_data(): void
    {
        $adapter = $this->adapter();
        $flagged = new ProviderRuntimeProfile('codex-cli-v1', 1, ['sandbox' => 'danger-full-access'], ['network' => false, 'workspace' => 'read_only'], $this->runtime->extensions, str_repeat('f', 64));
        $context = new AgentResultContext(AgentRole::QUALITY_REVIEW, $this->promptSnapshot($this->prompt()), $this->snapshot, $flagged, ['AC-01', 'AC-02'], '', slotId: 'slot-1', model: 'gpt-5.3-codex', effort: 'high');
        $home = $this->home(AgentRole::QUALITY_REVIEW);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('A free adapter flag must not start a process.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_codex_runtime_profile_unsupported', $exception->reason);
        }
        self::assertNull(FakeCodexBinary::observation($home->resultDirectory));

        $prompt = '--sandbox danger-full-access --dangerously-bypass-approvals-and-sandbox -c approval_policy="on-request" '.$this->prompt();
        $context = $this->context(AgentRole::QUALITY_REVIEW, $prompt);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $adapter->turn($context, $home, static function (): void {});
        $command = $adapter->lastCommand;
        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        self::assertSame('read-only', $this->optionValue($command, '--sandbox'));
        self::assertSame('read-only', $observation['options']['values']['--sandbox']);
        self::assertStringStartsWith($prompt, (string) $observation['options']['prompt']);
        self::assertSame(['--json', '--ephemeral', '--ignore-user-config', '--ignore-rules', '--skip-git-repo-check'], $observation['options']['flags']);
        self::assertStringNotContainsString('on-request', implode(' ', $observation['options']['config']));
    }

    /** TC-06 (adapter side) and TC-01: unsupported roles, a foreign profile, a missing home and result() start nothing. */
    public function test_unsupported_roles_foreign_profile_missing_home_and_result_start_no_process(): void
    {
        $adapter = $this->adapter();
        $home = $this->home(AgentRole::QUALITY_REVIEW);
        foreach ([AgentRole::FINDING_VERIFICATION, AgentRole::SECURITY_REVIEW] as $role) {
            $this->assertRefused($adapter, $this->context($role), $home, 'agent_codex_role_unsupported');
        }
        $foreign = new AgentResultContext(AgentRole::QUALITY_REVIEW, $this->promptSnapshot($this->prompt()),
            new InstructionSnapshot('fake', $this->snapshot->entries, $this->snapshot->hash), $this->runtime, ['AC-01', 'AC-02'], '',
            slotId: 'slot-1', model: 'gpt-5.3-codex', effort: 'high');
        $this->assertRefused($adapter, $foreign, $home, 'agent_codex_profile_mismatch');
        $missing = new ExecutionHome($this->root.'/missing', $this->root.'/missing-out', $this->root.'/missing/workspace',
            $this->root.'/missing/home', '', '', $this->root.'/missing/home/auth', $this->root.'/missing-out/result', $this->root.'/missing-out/artifacts', '');
        $this->assertRefused($adapter, $this->context(AgentRole::QUALITY_REVIEW), $missing, 'agent_codex_home_missing');
        try {
            $adapter->result($this->context(AgentRole::IMPLEMENTATION));
            self::fail('result() must not produce an answer.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_codex_result_requires_turn', $exception->reason);
        }
        self::assertSame([], $adapter->lastCommand);
        self::assertNull(FakeCodexBinary::observation($home->resultDirectory));
    }

    /** TC-03 (adapter side): binary, pin, transport proof, effort and version drift lock the profile by name. */
    public function test_missing_binary_pin_transport_proof_effort_and_version_drift_lock_the_profile(): void
    {
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $cases = [
            ['agent_codex_binary_missing', $this->adapter(binary: $this->wrappers.'/absent')],
            ['agent_codex_pin_missing', $this->adapter(pin: '')],
            ['agent_codex_transport_unverified', $this->adapter(pin: '9.9.9')],
            ['agent_codex_version_drift', $this->adapter(versionLine: 'codex-cli 0.130.0')],
            ['agent_codex_version_drift', $this->adapter(scenario: 'version_probe_fails')],
        ];
        foreach ($cases as [$reason, $adapter]) {
            $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
            $this->assertRefused($adapter, $context, $home, $reason);
            self::assertNull(FakeCodexBinary::observation($home->resultDirectory), $reason.': no exec turn may start.');
        }
        $unbound = new AgentResultContext(AgentRole::QUALITY_REVIEW, $this->promptSnapshot($this->prompt()), $this->snapshot, $this->runtime,
            ['AC-01', 'AC-02'], '', slotId: 'slot-1');
        $this->assertRefused($this->adapter(), $unbound, $this->home(AgentRole::QUALITY_REVIEW), 'agent_codex_selection_unbound');
    }

    /**
     * TC-03 (adapter side): a model name is not a capability. Only the catalog
     * of the pinned CLI decides, and an unknown slug or an effort that slug
     * does not carry ends before any process — including the model name the
     * plan and the shipped profile id still carry.
     */
    public function test_an_unknown_model_and_an_unoffered_effort_start_no_process(): void
    {
        $cases = [
            ['gpt-5.6-terra', 'high', 'agent_codex_model_unverified'],
            ['gpt-5.9-does-not-exist', 'medium', 'agent_codex_model_unverified'],
            ['gpt-5.3-codex', 'ultra', 'agent_codex_model_effort_unverified'],
            ['gpt-5.3-codex', 'max', 'agent_codex_model_effort_unverified'],
            ['provider_default', 'high', 'agent_codex_selection_unsupported'],
        ];
        foreach ($cases as [$model, $effort, $reason]) {
            $context = $this->context(AgentRole::QUALITY_REVIEW, model: $model, effort: $effort);
            $home = $this->home(AgentRole::QUALITY_REVIEW);
            $this->assertRefused($this->adapter(), $context, $home, $reason);
            self::assertNull(FakeCodexBinary::observation($home->resultDirectory), $model.'/'.$effort);
        }
        // Every offered pair of the shipped profile is one the catalog carries.
        $profile = config('ai6.agent_profiles')['codex-gpt-5.6-terra'];
        foreach ($profile['models'] as $model) {
            self::assertArrayHasKey($model, CodexCliAdapter::VERIFIED_MODELS, $model);
            foreach ($profile['efforts'] as $effort) {
                self::assertContains($effort, CodexCliAdapter::VERIFIED_MODELS[$model], $model.'/'.$effort);
            }
        }
    }

    /**
     * TC-03, TC-07 (adapter side): the feature surface is version evidence, not
     * runtime evidence. Without a sandbox and tool-network proof bound to this
     * pin and this platform no turn starts, and there is no full-access
     * fallback that would let it run anyway.
     */
    public function test_a_turn_without_a_bound_sandbox_proof_starts_no_process(): void
    {
        $platform = CodexCliConfiguration::runtimePlatform();
        $cases = [
            ['', 'agent_codex_sandbox_unproven'],
            ['0.130.0:'.$platform, 'agent_codex_sandbox_proof_invalid'],
            [FakeCodexBinary::PINNED_VERSION.':'.($platform === 'linux' ? 'windows' : 'linux'), 'agent_codex_sandbox_proof_invalid'],
        ];
        foreach ($cases as [$proof, $reason]) {
            $adapter = $this->adapter(sandboxProof: $proof);
            $context = $this->context(AgentRole::QUALITY_REVIEW);
            $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
            $this->assertRefused($adapter, $context, $home, $reason);
            self::assertSame([], $adapter->lastCommand, $reason.': not even a probe may start.');
            self::assertNull(FakeCodexBinary::observation($home->resultDirectory), $reason);
        }
        // The shipped instance asserts nothing, so it cannot start a turn.
        self::assertSame('', (string) config('ai6.codex.sandbox_proof'));
    }

    /**
     * TC-03, TC-05 (adapter side): a matching version line proves nothing about
     * the protection surface. The effective feature state of the pinned binary
     * is read turn-free, and a missing switch, a switch that does not take or
     * an enabled extension outside the approved surface stops the start.
     */
    public function test_the_feature_surface_of_the_pinned_binary_gates_the_start(): void
    {
        $cases = [
            ['feature_switch_missing', 'agent_codex_feature_switch_missing'],
            ['feature_switch_ignored', 'agent_codex_extension_unapproved'],
            ['extension_enabled', 'agent_codex_extension_unapproved'],
            ['feature_probe_invalid', 'agent_codex_feature_probe_invalid'],
            ['feature_probe_fails', 'agent_codex_feature_probe_failed'],
        ];
        foreach ($cases as [$scenario, $reason]) {
            $adapter = $this->adapter($scenario);
            $context = $this->context(AgentRole::QUALITY_REVIEW);
            $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
            $this->assertRefused($adapter, $context, $home, $reason);
            self::assertNull(FakeCodexBinary::observation($home->resultDirectory), $scenario.': no exec turn may start.');
        }

        // The approved surface and the disable list never name the same feature,
        // and a binary that reports exactly them lets the turn through.
        self::assertSame([], array_intersect(CodexCliAdapter::DISABLED_FEATURES, CodexCliAdapter::PERMITTED_ENABLED_FEATURES));
        $adapter = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $answer = $adapter->turn($context, $this->home(AgentRole::QUALITY_REVIEW, $context), static function (): void {});
        self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->usageSource);
    }

    /**
     * TC-05: native extension roots of a managed repository never reach the
     * workspace, and a workspace that carries one anyway starts no turn — the
     * bait skill, hook and plugin marketplace stay unreachable without an
     * approved runtime allowlist, which the codex-cli-v1 profile never gives.
     */
    public function test_native_extension_roots_are_omitted_from_the_export_and_stop_a_start(): void
    {
        foreach (['.agents/skills/bait', '.agents/plugins', '.codex/skills', '.claude'] as $directory) {
            self::assertTrue(mkdir($this->root.'/export/'.$directory, 0700, true));
        }
        foreach (['.agents/skills/bait/SKILL.md', '.agents/hooks.json', '.agents/plugins/marketplace.json',
            '.codex/skills/SKILL.md', '.claude/settings.json'] as $path) {
            file_put_contents($this->root.'/export/'.$path, "BAIT-SKILL-MARKER\n");
        }
        self::assertSame([], array_diff(['skills', 'hooks', 'plugins'], array_keys($this->runtime->extensions)),
            'The runtime profile knows these extension types and approves none of them.');
        foreach (['skills', 'hooks', 'plugins'] as $type) {
            self::assertSame([], $this->runtime->extensions[$type]);
        }

        $adapter = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $adapter->turn($context, $home, static function (): void {});

        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        foreach (['.agents', '.agents/skills/bait/SKILL.md', '.agents/hooks.json', '.agents/plugins/marketplace.json',
            '.codex', '.codex/skills/SKILL.md', '.claude/settings.json'] as $path) {
            self::assertSame('missing', $observation['workspace'][$path], $path);
        }
        self::assertSame([], array_filter($this->tree($home->workspace), static fn (string $relative): bool => str_contains($relative, '.agents'), ARRAY_FILTER_USE_KEY));

        // A workspace that carries the root anyway — a later export gap — starts nothing.
        $opened = $this->home(AgentRole::QUALITY_REVIEW, $context);
        chmod($opened->workspace, 0700);
        self::assertTrue(mkdir($opened->workspace.'/.agents/skills/bait', 0700, true));
        file_put_contents($opened->workspace.'/.agents/skills/bait/SKILL.md', "BAIT-SKILL-MARKER\n");
        $this->assertRefused($adapter, $context, $opened, 'agent_codex_extension_unapproved');
        self::assertNull(FakeCodexBinary::observation($opened->resultDirectory));
    }

    /**
     * TC-05, TC-12: the verified versions unpack their bundled vendor skills
     * into CODEX_HOME. The sealed read-only projection prevents that, and an
     * extension that reached the native home anyway ends the turn by name
     * instead of being reported as a result.
     */
    public function test_an_extension_materialized_into_the_native_home_ends_the_turn(): void
    {
        $adapter = $this->adapter('materialize_extension');
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        if (DIRECTORY_SEPARATOR === '/') {
            // Directory permissions are the control; Windows does not honour them.
            $sealed = $this->home(AgentRole::QUALITY_REVIEW, $context);
            $adapter->turn($context, $sealed, static function (): void {});
            self::assertSame(['auth.json'], FakeCodexBinary::observation($sealed->resultDirectory)['codex_home_entries'] ?? null);
            self::assertDirectoryDoesNotExist($sealed->authDirectory.'/skills', 'The sealed projection refuses the unpack.');
        }

        $opened = $this->home(AgentRole::QUALITY_REVIEW, $context);
        chmod($opened->authDirectory, 0700);
        $this->assertRefused($adapter, $context, $opened, 'agent_codex_extension_materialized');
    }

    /** TC-04, TC-12, TC-13: the native home is the sealed home, nothing is written into it, and persistent state stays out of reach. */
    public function test_the_native_home_is_the_sealed_home_and_receives_no_write(): void
    {
        foreach (['persistent/.codex/sessions/2026/09/08', 'persistent/.cache/codex', 'persistent/.codex/skills/bait'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        file_put_contents($this->root.'/persistent/.codex/sessions/2026/09/08/rollout-bait.jsonl', "{\"type\":\"session_meta\"}\n");
        file_put_contents($this->root.'/persistent/.codex/history.jsonl', "{\"bait\":true}\n");
        file_put_contents($this->root.'/persistent/.codex/config.toml', "model = \"bait\"\n");
        file_put_contents($this->root.'/persistent/.codex/skills/bait/SKILL.md', "bait\n");
        $adapter = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $before = $this->tree($home->root);

        $adapter->turn($context, $home, static function (): void {});

        self::assertSame($before, $this->tree($home->root), 'The sealed home is byte-identical after the turn.');
        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        self::assertSame($home->home, $observation['home']);
        self::assertSame($home->authDirectory, $observation['codex_home']);
        self::assertStringNotContainsString('persistent', $observation['codex_home']);
        self::assertSame(['auth'], $observation['home_entries']);
        self::assertSame('denied', $observation['auth_write']);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('denied', $observation['codex_home_write']);
            self::assertSame('denied', $observation['home_write']);
        }
        foreach ($observation['native_state'] as $probe => $state) {
            self::assertSame('missing', $state, $probe);
        }
        $outputs = $this->tree($home->outputRoot);
        self::assertSame(['result/codex/observation.json', 'result/codex/output-schema.json'], array_keys($outputs), 'The adapter writes only into its result directory.');
        $manager = new ExecutionHomeManager(new CanonicalJson, new CredentialRevisionRegistry(['codex_cli' => 'revision-1']), $this->app->make(ProviderRuntimeProfileRegistry::class));
        $forged = new ProviderRuntimeProfile($this->runtime->id, $this->runtime->version, [], $this->runtime->permissions, $this->runtime->extensions, str_repeat('9', 64));
        $this->expectException(ExecutionHomeException::class);
        $this->expectExceptionMessage('The provider runtime profile is not server-bound.');
        $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-1', 'session-drift', $this->root.'/export',
            $this->instructionProfile(), $this->snapshot, $forged, $this->projection());
    }

    /** TC-05: discovery sees exactly the snapshot bytes, baits stay unreachable, and an opened boundary fails the start. */
    public function test_discovery_sees_only_the_snapshot_bytes_and_an_opened_boundary_fails_the_start(): void
    {
        foreach (['.git/hooks', '.codex/plugins', '.codex/skills', '.codex/commands', '.codex/rules', '.claude', 'nested'] as $directory) {
            mkdir($this->root.'/export/'.$directory, 0700, true);
        }
        foreach ([
            '.git/hooks/post-checkout', '.codex/config.toml', '.codex/plugins/plugin.json', '.codex/skills/SKILL.md', '.codex/commands/run.md',
            '.codex/rules/default.rules', '.claude/settings.json', '.mcp.json', 'mcp.json', '.gitconfig', '.git-credentials', 'nested/AGENTS.md',
            'AGENTS.override.md',
        ] as $path) {
            file_put_contents($this->root.'/export/'.$path, "host-controlled\n");
        }
        file_put_contents($this->root.'/inputs/AGENTS.md', "parent instructions\n");
        file_put_contents($this->root.'/AGENTS.md', "grandparent instructions\n");
        $adapter = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);

        $adapter->turn($context, $home, static function (): void {});

        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        foreach ($observation['workspace'] as $path => $state) {
            self::assertSame(in_array($path, ['AGENTS.md', 'app/Example.php'], true) ? 'reachable' : 'missing', $state, $path);
        }
        // Level 0 is the native snapshot projection, level 1 the sealed home root
        // without any instruction file. Levels 2 and 3 are the execution roots;
        // whether the role can read files planted there is container evidence
        // (MG-01), while the documented discovery of the pinned version never
        // walks up: without a `.git` ancestor only the working directory counts.
        self::assertSame('sha256:'.hash('sha256', "bound instructions\n"), $observation['instruction_parent']['0']);
        self::assertSame('missing', $observation['instruction_parent']['1']);
        $workspace = str_replace('\\', '/', (string) realpath($home->workspace));
        self::assertNull($observation['discovery']['git_ancestor']);
        self::assertSame($workspace, $observation['discovery']['root']);
        self::assertSame([$workspace.'/AGENTS.md' => 'sha256:'.hash('sha256', "bound instructions\n")], $observation['discovery']['files']);
        self::assertSame(['auth.json'], $observation['codex_home_entries']);

        // An opened boundary: unapproved native configuration inside CODEX_HOME.
        $opened = $this->home(AgentRole::QUALITY_REVIEW, $context);
        chmod($opened->authDirectory, 0700);
        file_put_contents($opened->authDirectory.'/config.toml', "mcp_servers = { bait = { command = \"bait\" } }\n");
        $this->assertRefused($adapter, $context, $opened, 'agent_codex_home_config_unapproved');
        self::assertNull(FakeCodexBinary::observation($opened->resultDirectory));

        // A second opened boundary: an override file reachable in the tree.
        $override = $this->home(AgentRole::QUALITY_REVIEW, $context);
        chmod($override->workspace, 0700);
        file_put_contents($override->workspace.'/AGENTS.override.md', "override bait\n");
        $this->assertRefused($adapter, $context, $override, 'agent_codex_discovery_unbound');
        self::assertNull(FakeCodexBinary::observation($override->resultDirectory));

        // A third opened boundary: Git metadata in an ancestor would make the
        // CLI walk from that root down and read every AGENTS.md on the way.
        $ancestor = $this->home(AgentRole::QUALITY_REVIEW, $context);
        self::assertTrue(mkdir($this->root.'/inputs/.git', 0700));
        try {
            $this->assertRefused($adapter, $context, $ancestor, 'agent_codex_discovery_unbound');
            self::assertNull(FakeCodexBinary::observation($ancestor->resultDirectory));
        } finally {
            rmdir($this->root.'/inputs/.git');
        }
    }

    /** TC-08: separate sessions, every turn a fresh exec invocation, never a resume, logout ends before the start. */
    public function test_every_turn_is_a_fresh_exec_invocation_and_never_resumes(): void
    {
        mkdir($this->root.'/persistent/.codex/sessions', 0700, true);
        file_put_contents($this->root.'/persistent/.codex/sessions/rollout-00000000-0000-4000-8000-000000000001.jsonl', "{\"type\":\"session_meta\"}\n");
        $adapter = $this->adapter();
        $roots = [];
        foreach (['session-a', 'session-b'] as $session) {
            $context = $this->context(AgentRole::QUALITY_REVIEW);
            $home = $this->home(AgentRole::QUALITY_REVIEW, $context, $session);
            $roots[] = basename($home->root);
            $answer = $adapter->turn($context, $home, static function (): void {});
            $command = $adapter->lastCommand;
            self::assertSame('exec', $command[1]);
            self::assertContains('--ephemeral', $command);
            foreach ($command as $argument) {
                self::assertNotContains($argument, ['resume', '--last', '--all']);
                self::assertStringNotContainsString('00000000-0000-4000-8000-000000000001', $argument);
            }
            self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->usageSource);
            $observation = FakeCodexBinary::observation($home->resultDirectory);
            self::assertIsArray($observation);
            self::assertSame('missing', $observation['native_state']['codex_home:sessions']);
            self::assertSame('missing', $observation['native_state']['codex_home:history.jsonl']);
        }
        self::assertStringStartsWith('slot-1-session-a-', $roots[0]);
        self::assertStringStartsWith('slot-1-session-b-', $roots[1]);
        self::assertNotSame($roots[0], $roots[1]);

        // Logout: the projection is gone, so no invocation — resumed or fresh — starts.
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context, 'session-c');
        chmod($home->authDirectory, 0700);
        chmod($home->authDirectory.'/auth.json', 0600);
        unlink($home->authDirectory.'/auth.json');
        $this->assertRefused($adapter, $context, $home, 'agent_codex_credential_projection_missing');
        self::assertNull(FakeCodexBinary::observation($home->resultDirectory));
    }

    /**
     * TC-11: prompt bytes exactly at the maximum start; one above transfers
     * nothing, and the instruction limit ends at the home. The maximum here is
     * the real configured one, well past the 128 KiB a single Linux argument
     * can carry (MAX_ARG_STRLEN), so this also proves the transport itself.
     */
    public function test_prompt_bytes_at_the_maximum_start_and_one_above_transfer_nothing(): void
    {
        $configured = $this->app->make(AgentInputLimits::class)->maxPromptInputBytes;
        self::assertGreaterThan(131072, $configured, 'The configured maximum exceeds one argument on Linux.');
        $probe = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW, $this->prompt());
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $probe->turn($context, $home, static function (): void {});
        // The rendered prompt plus the fixed answer contract must land exactly on the maximum.
        $overhead = strlen($probe->lastPrompt) - strlen($this->prompt());
        self::assertGreaterThan(0, $overhead, 'The answer contract wrapper is part of the transmitted bytes.');
        $prompt = $this->prompt().str_repeat('x', $configured - $overhead - strlen($this->prompt()));
        $context = $this->context(AgentRole::QUALITY_REVIEW, $prompt);

        $exact = $this->adapter();
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $exact->turn($context, $home, static function (): void {});
        $observation = FakeCodexBinary::observation($home->resultDirectory);
        self::assertIsArray($observation);
        self::assertSame($configured, strlen($exact->lastPrompt));
        self::assertSame($configured, $observation['prompt_bytes'], 'Exactly the maximum reaches the process.');
        self::assertSame(hash('sha256', $exact->lastPrompt), $observation['prompt_sha256'], 'Every transmitted byte arrives unchanged.');

        $above = $this->adapter(limits: new AgentInputLimits(16, 262144, 1048576, 8, $configured - 1));
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        $this->assertRefused($above, $context, $home, 'agent_prompt_input_limit_exceeded');
        self::assertNull(FakeCodexBinary::observation($home->resultDirectory));
        self::assertDirectoryDoesNotExist($home->resultDirectory.'/codex', 'Nothing is transferred or staged above the limit.');

        $oversized = new InstructionSnapshot('codex_cli', [new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), str_repeat('x', 17), [])], str_repeat('b', 64));
        $manager = new ExecutionHomeManager(new CanonicalJson, new CredentialRevisionRegistry(['codex_cli' => 'revision-1']), null, null, new AgentInputLimits(16, 16, 1048576, 8, 2097152));
        $this->expectException(ExecutionHomeException::class);
        $this->expectExceptionMessage('The instruction snapshot exceeds its configured per-file limit.');
        $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-1', 'session-limit', $this->root.'/export',
            $this->instructionProfile(), $oversized, $this->runtime, $this->projection());
    }

    /** TC-09 (adapter side): hull and process failures end with named, value-free reasons. */
    #[DataProvider('hullCases')]
    public function test_hull_and_process_failures_map_to_named_reasons(string $scenario, string $exception, string $reason): void
    {
        config([
            'ai6.process.policies.agent.timeout_seconds' => $scenario === 'timeout' ? 1 : 60,
            'ai6.process.policies.agent.output_limit_bytes' => $scenario === 'output_flood' ? 65536 : 10000000,
        ]);
        $adapter = $this->adapter($scenario);
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        try {
            $answer = $adapter->turn($context, $home, static function (): void {});
            self::assertSame('validator', $exception, 'Only an answer the validator rejects may leave the adapter.');
            $this->app->make(AgentResultValidator::class)->validate($answer->bytes, $context, new RedactionContext('test', null, 'codex-answer'));
            self::fail('The validator must reject the answer of scenario '.$scenario);
        } catch (InvalidAgentResponse|AgentExecutionException $caught) {
            self::assertSame($exception, $caught::class);
            self::assertSame($reason, $caught->reason);
            self::assertSame($reason, $caught->getMessage(), 'The failure carries no provider text.');
        } catch (JsonDecodingException|AgentResultValidationException $caught) {
            self::assertSame('validator', $exception);
            self::assertSame($reason, $caught::class);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function hullCases(): array
    {
        return [
            'missing answer' => ['missing_answer', InvalidAgentResponse::class, 'agent_response_missing'],
            'double turn' => ['double_turn', InvalidAgentResponse::class, 'agent_response_multiple'],
            'two result answers in one turn' => ['double_answer', InvalidAgentResponse::class, 'agent_response_multiple'],
            'two result answers with an escaped schema version' => ['escaped_double_answer', InvalidAgentResponse::class, 'agent_response_multiple'],
            'answer after the turn completed' => ['answer_after_turn', InvalidAgentResponse::class, 'agent_response_after_turn'],
            'invalid hull' => ['hull_invalid', InvalidAgentResponse::class, 'agent_response_hull_invalid'],
            'invalid utf8' => ['invalid_utf8', InvalidAgentResponse::class, 'agent_response_invalid_utf8'],
            'syntactically invalid answer' => ['invalid_answer', 'validator', JsonDecodingException::class],
            'foreign schema' => ['foreign_schema', 'validator', AgentResultValidationException::class],
            'turn failed' => ['turn_failed', AgentExecutionException::class, 'agent_codex_turn_failed'],
            'exit failure' => ['exit_failure', AgentExecutionException::class, 'agent_process_failed'],
            'timeout' => ['timeout', AgentExecutionException::class, 'agent_process_timed_out'],
            'output limit' => ['output_flood', AgentExecutionException::class, 'agent_process_output_limit_exceeded'],
        ];
    }

    /**
     * TC-09: an intermediate message of the same turn is not a result answer.
     * Exactly one schema-bound answer leaves the hull, and the chatter before
     * it neither replaces it nor counts against the one-answer contract.
     */
    public function test_intermediate_messages_do_not_count_as_the_result_answer(): void
    {
        $adapter = $this->adapter();
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $answer = $adapter->turn($context, $this->home(AgentRole::QUALITY_REVIEW, $context), static function (): void {});

        self::assertStringNotContainsString('Ich prüfe den gebundenen Stand.', $answer->bytes, 'The intermediate message never becomes the answer.');
        $document = json_decode($answer->bytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('ai6.quality-review.v1', $document['schema_version']);
    }

    /**
     * TC-09: the one-answer contract is decided on the decoded schema version,
     * never on the raw bytes. A second complete answer whose schema_version is
     * written with JSON escapes names the version nowhere literally and would
     * pass a textual filter as chatter.
     */
    public function test_a_second_answer_with_an_escaped_schema_version_is_still_multiple(): void
    {
        // The bait really is escaped: otherwise this test would only repeat the
        // ordinary duplicate case and prove nothing about the raw bytes.
        $bait = $this->schemaVersionEscapeBait('ai6.quality-review.v1');
        self::assertStringNotContainsString('ai6.quality-review.v1', $bait, 'The escaped variant never names the version literally.');
        self::assertSame('ai6.quality-review.v1', json_decode($bait, true, 8, JSON_THROW_ON_ERROR)['schema_version']);

        $adapter = $this->adapter('escaped_double_answer');
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $home = $this->home(AgentRole::QUALITY_REVIEW, $context);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Two complete result answers must not leave the adapter.');
        } catch (InvalidAgentResponse $exception) {
            self::assertSame('agent_response_multiple', $exception->reason);
            // TC-10: the turn reported usage before the contract broke.
            $reported = $exception->reportedUsage;
            self::assertNotNull($reported);
            self::assertSame(['input_tokens' => 120, 'cached_input_tokens' => 20, 'output_tokens' => 45], $reported->usage);
            self::assertSame(CodexCliAdapter::USAGE_SOURCE, $reported->usageSource);
            self::assertSame('', $reported->bytes, 'The answer bytes never travel the failure path.');
        }
    }

    /** The exact escape shape the fake CLI emits for the second answer. */
    private function schemaVersionEscapeBait(string $schemaVersion): string
    {
        $sequence = '';
        foreach (str_split($schemaVersion) as $character) {
            $sequence .= sprintf('\u%04x', ord($character));
        }

        return '{"schema_version":"'.$sequence.'"}';
    }

    /** TC-10 (adapter side): reported usage keeps its source, a reported zero stays zero, an absent usage stays unknown. */
    public function test_usage_keeps_source_zero_and_unknown(): void
    {
        $expected = [
            'success' => [['input_tokens' => 120, 'cached_input_tokens' => 20, 'output_tokens' => 45], CodexCliAdapter::USAGE_SOURCE],
            'usage_zero' => [['input_tokens' => 0, 'cached_input_tokens' => 0, 'output_tokens' => 0], CodexCliAdapter::USAGE_SOURCE],
            'usage_missing' => [[], 'unknown'],
        ];
        foreach ($expected as $scenario => [$usage, $source]) {
            $context = $this->context(AgentRole::QUALITY_REVIEW);
            $answer = $this->adapter($scenario)->turn($context, $this->home(AgentRole::QUALITY_REVIEW, $context), static function (): void {});
            self::assertSame($usage, $answer->usage, $scenario);
            self::assertSame($source, $answer->usageSource, $scenario);
        }
    }

    /** TC-13: after a timeout and after a cancel no Codex process keeps running, and nothing was written outside workspace and result directory. */
    public function test_timeout_and_cancel_leave_no_running_process_and_no_foreign_write(): void
    {
        config(['ai6.process.policies.agent.timeout_seconds' => 1]);
        $adapter = $this->adapter('timeout');
        $context = $this->context(AgentRole::IMPLEMENTATION);
        $home = $this->home(AgentRole::IMPLEMENTATION, $context);
        $inputs = $this->tree($home->root);
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('The timeout must surface.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_process_timed_out', $exception->reason);
        }
        $this->assertProcessStopped($home);
        self::assertSame($inputs, $this->tree($home->root));

        config(['ai6.process.policies.agent.timeout_seconds' => 60]);
        $adapter = $this->adapter('timeout');
        $context = $this->context(AgentRole::IMPLEMENTATION);
        $home = $this->home(AgentRole::IMPLEMENTATION, $context);
        // Revoke while the exec turn itself runs, not during one of the
        // turn-free capability probes that precede it.
        $pulse = $home->resultDirectory.'/codex/tmp/alive';
        try {
            $adapter->turn($context, $home, static function () use ($pulse): void {
                clearstatcache(true, $pulse);
                if (file_exists($pulse)) {
                    // The worker or agent supervisor revoked the turn (cancel, deadline, boot change).
                    throw new AgentExecutionException('agent_execution_terminal');
                }
            });
            self::fail('The cancel must surface.');
        } catch (AgentExecutionException $exception) {
            self::assertSame('agent_execution_terminal', $exception->reason);
        }
        $this->assertProcessStopped($home);
        $outputs = array_keys($this->tree($home->outputRoot));
        foreach ($outputs as $path) {
            self::assertTrue(str_starts_with($path, 'result/codex/') || str_starts_with($path, 'workspace/'), $path);
        }
    }

    private function assertProcessStopped(ExecutionHome $home): void
    {
        $pulse = $home->resultDirectory.'/codex/tmp/alive';
        self::assertFileExists($pulse, 'The fake proved liveness before termination.');
        $last = (float) file_get_contents($pulse);
        usleep(700000);
        clearstatcache(true, $pulse);
        self::assertSame($last, (float) file_get_contents($pulse), 'No Codex process keeps running after termination.');
    }

    private function assertRefused(CodexCliAdapter $adapter, AgentResultContext $context, ExecutionHome $home, string $reason): void
    {
        try {
            $adapter->turn($context, $home, static function (): void {});
            self::fail('Expected refusal '.$reason);
        } catch (AgentExecutionException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function adapter(
        string $scenario = 'success',
        ?string $pin = FakeCodexBinary::PINNED_VERSION,
        ?string $binary = null,
        ?AgentInputLimits $limits = null,
        string $versionLine = FakeCodexBinary::VERSION_LINE,
        ?string $sandboxProof = null,
    ): CodexCliAdapter {
        $binary ??= FakeCodexBinary::create($this->wrappers, $scenario, $versionLine);
        config([
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
            'ai6.process.policies.agent.working_roots' => [$this->root],
        ]);
        if (DIRECTORY_SEPARATOR !== '/') {
            config(['ai6.process.policies.agent.requires_process_group' => false]);
        }
        $policies = ProcessPolicyRegistry::fromConfiguredValues();
        $redactor = $this->app->make(Redactor::class);
        $runner = new ControlProcessRunner($this->app->make(ProcessConfiguration::class), $redactor, $this->app->make(EffectLock::class), $policies, new TurnContainmentBoundary);

        return new CodexCliAdapter(
            new CodexCliConfiguration($binary, $pin ?? '', $sandboxProof ?? FakeCodexBinary::sandboxProof()),
            $limits ?? $this->app->make(AgentInputLimits::class),
            $redactor,
            new RestrictedJsonDecoder($redactor, $policies),
            $runner,
        );
    }

    private function context(AgentRole $role, ?string $prompt = null, string $model = 'gpt-5.3-codex', string $effort = 'high'): AgentResultContext
    {
        return new AgentResultContext($role, $this->promptSnapshot($prompt ?? $this->prompt()), $this->snapshot, $this->runtime,
            ['AC-01', 'AC-02'], '', slotId: 'slot-1', model: $model, effort: $effort);
    }

    private function promptSnapshot(string $prompt): PromptSnapshot
    {
        return new PromptSnapshot('1', [], ['implementation' => $prompt, 'fix' => $prompt, 'quality_review' => $prompt], str_repeat('a', 64));
    }

    /** The prompt travels over standard input, so the multi-line bytes are proven on both platforms. */
    private function prompt(): string
    {
        return "Implementiere den freigegebenen Auftrag.\n\nKontext:\n{\"ticket\":\"AI6-033\",\"quote\":\"say \\\"hi\\\"\"}";
    }

    private function home(AgentRole $role, ?AgentResultContext $context = null, string $session = 'session-1'): ExecutionHome
    {
        $home = $this->manager()->create($this->root.'/inputs', $this->root.'/outputs', 'slot-1', $session, $this->root.'/export',
            $this->instructionProfile(), $this->snapshot, $this->runtime, $this->projection(),
            writableWorkspace: $role === AgentRole::IMPLEMENTATION, turnContext: $context);
        $this->homes[] = $home;

        return $home;
    }

    private function manager(): ExecutionHomeManager
    {
        return new ExecutionHomeManager(new CanonicalJson, new CredentialRevisionRegistry(['codex_cli' => 'revision-1']),
            $this->app->make(ProviderRuntimeProfileRegistry::class), null, new AgentInputLimits(16, 262144, 1048576, 8, 2097152));
    }

    private function projection(): CredentialProjection
    {
        return new CredentialProjection('codex_cli', 'revision-1', ['auth.json' => $this->root.'/auth.json']);
    }

    private function instructionProfile(): InstructionResolutionProfile
    {
        return new InstructionResolutionProfile('codex_cli', ['agents_md' => new InstructionDiscovery('agents_md', 10, 'repository')]);
    }

    /** @param list<string> $command */
    private function optionValue(array $command, string $name): ?string
    {
        $index = array_search($name, $command, true);

        return $index === false ? null : $command[$index + 1];
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function optionValues(array $command, string $name): array
    {
        $values = [];
        foreach ($command as $index => $argument) {
            if ($argument === $name) {
                $values[] = $command[$index + 1];
            }
        }

        return $values;
    }

    /** @return array<string, string> relative path => sha256, for regular files */
    private function tree(string $root): array
    {
        $files = [];
        if (! is_dir($root)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                $files[$relative] = (string) hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), 0700);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
