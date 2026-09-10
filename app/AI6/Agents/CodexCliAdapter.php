<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use Throwable;

/**
 * The one Codex transport (AGT-010): the pinned CLI, started exactly once per
 * turn in non-interactive `exec` mode with a JSONL event stream and a
 * schema-bound final answer, inside the sealed execution home of AI6-046.
 *
 * Staging, mailbox, result documents, error mapping and home cleanup stay in
 * AgentExecutionRunner/AgentExecutionProcessor (AI6-047); the home, the
 * credential projection and the writable change output stay in
 * ExecutionHomeManager. This class only derives the argument list and the
 * environment from the sealed selection and the bound runtime profile,
 * checks the transmitted prompt bytes against the one input limit, reads the
 * two turn-free capability proofs of the pinned binary — its reported version
 * and its effective feature surface — and extracts the final answer from the
 * event hull for the central validator.
 */
final class CodexCliAdapter implements AgentAdapter
{
    public const PROVIDER_ALIAS = 'codex_cli';

    /**
     * Exact `codex --version` values whose `codex exec --help` surface was
     * verified to offer every flag in TRANSPORT_FLAGS. TRANSPORT_FLAGS,
     * CONFIG_OVERRIDES, DISABLED_FEATURES, PERMITTED_ENABLED_FEATURES and
     * VERIFIED_MODELS are all bound to exactly these versions. A pin outside
     * this list has no transport proof and never starts (AGT-010); a human
     * verification of a further version appends to it.
     */
    public const VERIFIED_TRANSPORT_VERSIONS = ['0.129.0-alpha.15'];

    /** @var list<string> The fixed exec surface of the verified versions. */
    public const TRANSPORT_FLAGS = [
        'exec', '--json', '--ephemeral', '--ignore-user-config', '--ignore-rules', '--skip-git-repo-check',
        '--color never', '--sandbox read-only|workspace-write', '--cd', '--model', '--config', '--disable',
        '--output-schema', '-- -',
    ];

    /**
     * The configuration overrides every turn passes, in this order after the
     * optional reasoning effort; each key is documented for the verified
     * versions and each value is fixed or derived from the one central limit.
     *
     * @var list<string>
     */
    public const CONFIG_OVERRIDES = [
        'approval_policy="never"',
        'sandbox_workspace_write.network_access=false',
        'sandbox_workspace_write.exclude_slash_tmp=true',
        'history.persistence="none"',
        'project_doc_max_bytes=<max_instruction_total_bytes>',
        'project_doc_fallback_filenames=[]',
        'mcp_servers={}',
    ];

    /**
     * The model catalog of the verified versions, read turn-free from
     * `codex debug models` of the pinned binary: every listed slug with the
     * reasoning efforts its own `supported_reasoning_levels` names. Hidden
     * catalog entries (`visibility: hide`) are internal review models and are
     * not offered. A model or a model/effort pair outside this map has no
     * capability proof at the pinned CLI and never reaches a turn (AGT-010);
     * a further model is appended only with a human verification against the
     * pinned binary, exactly like VERIFIED_TRANSPORT_VERSIONS.
     *
     * @var array<string, list<string>>
     */
    public const VERIFIED_MODELS = [
        'gpt-5.2' => ['low', 'medium', 'high', 'xhigh'],
        'gpt-5.3-codex' => ['low', 'medium', 'high', 'xhigh'],
        'gpt-5.4' => ['low', 'medium', 'high', 'xhigh'],
        'gpt-5.4-mini' => ['low', 'medium', 'high', 'xhigh'],
        'gpt-5.5' => ['low', 'medium', 'high', 'xhigh'],
    ];

    /**
     * The one effort outside VERIFIED_MODELS: it passes no
     * model_reasoning_effort override at all and leaves the catalog's own
     * default_reasoning_level in force, so it needs no per-model proof.
     */
    public const PROVIDER_DEFAULT_EFFORT = 'provider_default';

    /** @var list<AgentRole> */
    public const SUPPORTED_ROLES = [AgentRole::IMPLEMENTATION, AgentRole::QUALITY_REVIEW];

    /** @var array<string, bool|string> The only permission set this transport can honour. */
    public const SUPPORTED_PERMISSIONS = ['network' => false, 'workspace' => 'read_only'];

    /**
     * Feature switches of the verified versions that the empty extension
     * lists of the bound runtime profile turn off explicitly. `skills` is
     * deliberately absent: the verified versions know no such switch, they
     * unpack their bundled vendor skills into `$CODEX_HOME/skills/.system`
     * at startup. That makes the sealed read-only auth projection the
     * control instead of a flag — nothing can be materialized there, and
     * assertNoMaterializedExtension() proves it after the turn.
     *
     * @var list<string>
     */
    public const DISABLED_FEATURES = [
        'plugins', 'hooks', 'apps', 'multi_agent', 'memories', 'browser_use', 'browser_use_external',
        'computer_use', 'in_app_browser', 'image_generation', 'skill_mcp_dependency_install',
    ];

    /**
     * The effective feature surface of the verified versions under exactly
     * the overrides of a turn, read turn-free from `codex features list`:
     * these features report `true`, every DISABLED_FEATURES entry reports
     * `false`, and every remaining feature of the binary reports `false`.
     * A feature the pinned binary reports as enabled without being named
     * here is an extension the runtime profile never approved — including
     * one that has no switch — and it stops the start (AGT-009, AGT-010).
     *
     * @var list<string>
     */
    public const PERMITTED_ENABLED_FEATURES = [
        'collaboration_modes', 'enable_request_compression', 'fast_mode', 'guardian_approval', 'personality',
        'shell_snapshot', 'shell_tool', 'sqlite', 'steer', 'terminal_resize_reflow', 'tool_call_mcp_elicitation',
        'tool_search', 'tool_suggest', 'tui_app_server', 'unavailable_dummy_tools', 'workspace_dependencies',
    ];

    /**
     * Directory names the verified versions read native extensions from —
     * skills, hooks, plugin marketplaces, commands and rules. The export of
     * AI6-046 omits them; this second look refuses a workspace that carries
     * one anyway.
     *
     * @var list<string>
     */
    public const NATIVE_EXTENSION_ROOTS = ['.agents', '.codex', '.claude'];

    public const USAGE_SOURCE = 'codex_cli_exec_json';

    /** @var list<string> */
    private const ENVIRONMENT_ALLOWLIST = ['PATH', 'HOME', 'CODEX_HOME', 'TMPDIR', 'LC_ALL', 'LANG'];

    private const VERSION_PROBE_TIMEOUT_SECONDS = 30;

    /** @var list<string> The argument list of the last exec start, for contract tests. */
    public array $lastCommand = [];

    /** The prompt bytes the last exec start handed to the child over standard input, for contract tests. */
    public string $lastPrompt = '';

    /** @var array<string, string> */
    public array $lastEnvironment = [];

    public function __construct(
        private readonly CodexCliConfiguration $configuration,
        private readonly AgentInputLimits $limits,
        private readonly Redactor $redactor,
        private readonly RestrictedJsonDecoder $json,
        private readonly ?ControlProcessRunner $processes = null,
    ) {}

    public function result(AgentResultContext $context): string
    {
        // Codex has no answer without its one exec turn. The deterministic,
        // process-free result() seam belongs to the FakeAgent only.
        throw new AgentExecutionException('agent_codex_result_requires_turn');
    }

    /** @param list<string> $unreachablePaths */
    public function turn(AgentResultContext $context, ExecutionHome $home, Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
    {
        $this->assertStartable($context, $home);
        $prompt = $this->prompt($context);
        if (strlen($prompt) > $this->limits->maxPromptInputBytes) {
            // Nothing has been transferred yet: neither a process nor a partial input.
            throw new AgentExecutionException('agent_prompt_input_limit_exceeded');
        }
        $workspace = $this->regularDirectory($home->workspace);
        $this->assertDiscoveryBound($workspace);
        $scratch = $home->resultDirectory.'/codex';
        $temporary = $scratch.'/tmp';
        foreach ([$scratch, $temporary] as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new AgentExecutionException('agent_codex_scratch_unavailable');
            }
        }
        // The native home is the sealed home; CODEX_HOME is its read-only
        // auth projection, so auth.json is found and nothing else exists
        // there. Volatile writes go to the throwaway TMPDIR in the result
        // directory, which disappears with the home.
        $environment = [
            'HOME' => $home->home,
            'CODEX_HOME' => $home->authDirectory,
            'TMPDIR' => $temporary,
            'LC_ALL' => 'C.UTF-8',
            'LANG' => 'C.UTF-8',
        ];
        $runner = $this->processes ?? app(ControlProcessRunner::class);
        $heartbeat();
        $this->assertPinnedVersion($runner, $workspace, $home, $environment, $heartbeat);
        $this->assertFeatureSurface($runner, $workspace, $home, $environment, $heartbeat);
        $schemaPath = $scratch.'/output-schema.json';
        $schema = json_encode($this->outputSchema($context), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($schemaPath, $schema, LOCK_EX) !== strlen($schema)) {
            throw new AgentExecutionException('agent_codex_scratch_unavailable');
        }
        $command = $this->command($context, $workspace, $schemaPath);
        $this->lastCommand = $command;
        $this->lastPrompt = $prompt;
        $this->lastEnvironment = $environment;
        $result = $this->execute($runner, $command, $workspace, $environment, $home, $heartbeat, null, 'codex-cli-turn', $prompt);
        $this->assertNoMaterializedExtension($home);
        if (! $result->succeeded()) {
            throw new AgentExecutionException('agent_process_'.$result->outcome->value);
        }

        return $this->answer($result->output, $this->schemaVersion($context));
    }

    /**
     * The JSON Schema of the one final answer, derived from the same role
     * contract AgentResultValidator enforces (AGT-004). It makes the model
     * emit exactly the allowed keys; the validator remains the only
     * authority on the bytes that come back.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(AgentResultContext $context): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => $string];
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $properties = [
            'schema_version' => ['type' => 'string', 'const' => $this->schemaVersion($context)],
            'status' => ['type' => 'string', 'enum' => array_map(
                static fn (AgentResultStatus $status): string => $status->value,
                AgentResultStatus::allowedFor($context->role),
            )],
            'summary' => $string,
            'prompt_snapshot_hash' => ['type' => 'string', 'const' => $context->promptSnapshot->hash],
            'instruction_snapshot_hash' => ['type' => 'string', 'const' => $context->instructionSnapshot->hash],
            'provider_runtime_profile_hash' => ['type' => 'string', 'const' => $context->runtimeProfile->hash],
            'human_request' => [
                'type' => ['object', 'null'],
                'properties' => [
                    'kind' => $string, 'title' => $string, 'message' => $string, 'why_needed' => $string,
                    'response_mode' => $string,
                    'options' => ['type' => 'array', 'items' => $object(['key' => $string, 'label' => $string])],
                    'recommended_option' => ['type' => ['string', 'null']],
                    'affected_paths' => $strings,
                    'criterion_refs' => $strings,
                ],
                'required' => ['kind', 'title', 'message', 'why_needed', 'response_mode', 'options', 'recommended_option', 'affected_paths', 'criterion_refs'],
                'additionalProperties' => false,
            ],
        ];
        $findingStatuses = ['type' => 'array', 'items' => $object([
            'finding_id' => ['type' => 'string', 'enum' => $context->expectedFindingIds],
            'status' => $string,
            'evidence' => $string,
        ])];
        if ($context->role === AgentRole::IMPLEMENTATION) {
            $properties['decisions'] = ['type' => 'array', 'items' => $object(['key' => $string, 'title' => $string, 'rationale' => $string])];
            $properties['changed_paths'] = $strings;
            $properties['open_manual_gates'] = $strings;
            $properties['implementation_summary'] = $object([
                'changed_components' => $strings, 'decisions' => $strings, 'assumptions' => $strings,
                'deviations' => $strings, 'known_limits' => $strings, 'tests' => $strings, 'review_focus' => $strings,
            ]);
            if ($context->expectedFindingIds !== []) {
                $properties['finding_statuses'] = $findingStatuses;
            }
            if ($context->instructionUpdate) {
                $properties['instruction_patch'] = [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'schema_version' => ['type' => 'string', 'const' => 'ai6.instruction-patch.v1'],
                        'path' => $string,
                        'expected_blob_sha' => ['type' => ['string', 'null']],
                        'format' => ['type' => 'string', 'const' => 'utf8_file_replacement_v1'],
                        'content_base64' => $string,
                        'content_length' => ['type' => 'integer'],
                        'content_sha256' => $string,
                    ],
                    'required' => ['schema_version', 'path', 'expected_blob_sha', 'format', 'content_base64', 'content_length', 'content_sha256'],
                    'additionalProperties' => false,
                ];
            }
        } else {
            $properties['findings'] = ['type' => 'array', 'items' => $object([
                'local_id' => $string, 'severity' => $string, 'disposition' => $string, 'category' => $string,
                'file' => $string, 'line' => ['type' => 'integer'], 'title' => $string, 'evidence' => $string,
                'expected_result' => $string,
                'criterion_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $context->criterionRefs]],
            ])];
            $properties['criterion_coverage'] = ['type' => 'array', 'items' => $object([
                'criterion_id' => ['type' => 'string', 'enum' => $context->criterionRefs],
                'status' => $string,
                'evidence' => $string,
            ])];
            $properties['instruction_recommendations'] = ['type' => 'array', 'items' => $object([
                'title' => $string, 'recommendation' => $string, 'reason' => $string,
            ])];
            if ($context->expectedFindingIds !== []) {
                $properties['finding_statuses'] = $findingStatuses;
            }
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $this->schemaVersion($context),
            ...$object($properties),
        ];
    }

    private function assertStartable(AgentResultContext $context, ExecutionHome $home): void
    {
        if (! in_array($context->role, self::SUPPORTED_ROLES, true)) {
            throw new AgentExecutionException('agent_codex_role_unsupported');
        }
        foreach ([$home->root, $home->home, $home->workspace, $home->resultDirectory, $home->artifactDirectory] as $directory) {
            if ($directory === '' || ! is_dir($directory) || is_link($directory)) {
                throw new AgentExecutionException('agent_codex_home_missing');
            }
        }
        if ($context->instructionSnapshot->providerProfileAlias !== self::PROVIDER_ALIAS) {
            throw new AgentExecutionException('agent_codex_profile_mismatch');
        }
        // Only the selection sealed by the worker reaches the command line;
        // its values share the closed character set of the profile allowlist.
        foreach ([$context->model, $context->effort] as $value) {
            if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $value) !== 1) {
                throw new AgentExecutionException('agent_codex_selection_unbound');
            }
        }
        if ($context->model === self::PROVIDER_DEFAULT_EFFORT) {
            throw new AgentExecutionException('agent_codex_selection_unsupported');
        }
        // A model name is not a capability: only the catalog of the pinned CLI
        // says which slug it knows and which efforts that slug carries. There
        // is no second, wider effort list beside it — that would be a set the
        // CLI never confirmed.
        $efforts = self::VERIFIED_MODELS[$context->model] ?? null;
        if ($efforts === null) {
            throw new AgentExecutionException('agent_codex_model_unverified');
        }
        if ($context->effort !== self::PROVIDER_DEFAULT_EFFORT && ! in_array($context->effort, $efforts, true)) {
            throw new AgentExecutionException('agent_codex_model_effort_unverified');
        }
        self::assertRuntimeProfile($context->runtimeProfile);
        if (! $this->configuration->binaryPresent()) {
            throw new AgentExecutionException('agent_codex_binary_missing');
        }
        if ($this->configuration->pinnedVersion === '') {
            throw new AgentExecutionException('agent_codex_pin_missing');
        }
        if (! in_array($this->configuration->pinnedVersion, self::VERIFIED_TRANSPORT_VERSIONS, true)) {
            throw new AgentExecutionException('agent_codex_transport_unverified');
        }
        // The feature surface is version evidence, not runtime evidence: it
        // says which switches the binary honours, never whether its sandbox
        // and tool-network boundaries hold in this runtime. The pinned CLI
        // has no turn-free oracle for that, so the boundary is asserted
        // separately and must bind to this pin and this platform. Without it
        // there is no full-access fallback — the turn does not start (AGT-009).
        if ($this->configuration->sandboxProof === '') {
            throw new AgentExecutionException('agent_codex_sandbox_unproven');
        }
        if (! $this->configuration->sandboxProofBinds()) {
            throw new AgentExecutionException('agent_codex_sandbox_proof_invalid');
        }
        $auth = $home->authDirectory.'/auth.json';
        if (! is_dir($home->authDirectory) || is_link($home->authDirectory) || ! is_file($auth) || is_link($auth)) {
            throw new AgentExecutionException('agent_codex_credential_projection_missing');
        }
        // CODEX_HOME is the projection directory itself: any other entry
        // there (config.toml, AGENTS.md, skills, plugins, sessions, history)
        // would be native configuration or state the profile never approved.
        foreach (scandir($home->authDirectory) ?: [] as $entry) {
            if (! in_array($entry, ['.', '..', 'auth.json'], true)) {
                throw new AgentExecutionException('agent_codex_home_config_unapproved');
            }
        }
    }

    /**
     * Native discovery of the verified versions walks from the project root
     * — the nearest ancestor holding `.git` — down to the working directory
     * and prefers AGENTS.override.md over AGENTS.md in every directory; only
     * without such an ancestor does it read the working directory alone. The
     * snapshot bytes are therefore what the CLI reads only if no ancestor of
     * the workspace carries Git metadata and no override file exists in the
     * tree. The same walk also activates native extensions from the
     * NATIVE_EXTENSION_ROOTS directories of a managed repository — skills,
     * hooks, plugin marketplaces, commands and rules — which no runtime
     * profile approves. ExecutionHomeManager omits all of those names; this
     * second look refuses the start when any condition fails (AGT-009).
     */
    private function assertDiscoveryBound(string $workspace): void
    {
        for ($ancestor = dirname($workspace); true; $ancestor = dirname($ancestor)) {
            $git = rtrim($ancestor, '/').'/.git';
            if (file_exists($git) || is_link($git)) {
                throw new AgentExecutionException('agent_codex_discovery_unbound');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            if ($name === 'AGENTS.override.md' || $entry->isLink()) {
                throw new AgentExecutionException('agent_codex_discovery_unbound');
            }
            if (in_array($name, self::NATIVE_EXTENSION_ROOTS, true)) {
                throw new AgentExecutionException('agent_codex_extension_unapproved');
            }
        }
    }

    /**
     * The verified versions unpack their bundled vendor skills and helper
     * binaries into `$CODEX_HOME` at startup — `skills/.system`, `tmp/arg0`,
     * state databases. The sealed read-only auth projection makes that
     * impossible instead of merely unlikely, and this check proves it for
     * the turn that just ran: an extension that reached the native home is a
     * containment failure, not a result (AGT-009).
     */
    private function assertNoMaterializedExtension(ExecutionHome $home): void
    {
        foreach (scandir($home->authDirectory) ?: [] as $entry) {
            if (! in_array($entry, ['.', '..', 'auth.json'], true)) {
                throw new AgentExecutionException('agent_codex_extension_materialized');
            }
        }
    }

    /**
     * The verified versions honour exactly this profile shape: no adapter
     * flags, tool network off, sealed tree read-only (the writable change
     * output of an implementation turn is derived from the role by AI6-046,
     * not from this permission), and no extension of any type. Anything else
     * cannot be rendered provably and stops the start (AGT-009, AGT-010).
     */
    public static function assertRuntimeProfile(ProviderRuntimeProfile $profile): void
    {
        $permissions = $profile->permissions;
        ksort($permissions, SORT_STRING);
        $expected = self::SUPPORTED_PERMISSIONS;
        ksort($expected, SORT_STRING);
        if ($profile->adapterFlags !== [] || $permissions !== $expected) {
            throw new AgentExecutionException('agent_codex_runtime_profile_unsupported');
        }
        foreach (RuntimeExtensionType::cases() as $type) {
            if (($profile->extensions[$type->value] ?? []) !== []) {
                throw new AgentExecutionException('agent_codex_runtime_profile_unsupported');
            }
        }
    }

    /**
     * The rendered role prompt of the bound snapshot plus the fixed answer
     * contract that tells the CLI's model which bindings the one final JSON
     * answer must carry. Every byte here is data: it is passed after `--`.
     */
    private function prompt(AgentResultContext $context): string
    {
        $key = match (true) {
            $context->role === AgentRole::QUALITY_REVIEW => 'quality_review',
            $context->expectedFindingIds !== [] => 'fix',
            default => 'implementation',
        };
        $rendered = $context->promptSnapshot->renderedPrompts[$key] ?? null;
        if (! is_string($rendered) || $rendered === '') {
            throw new AgentExecutionException('agent_codex_prompt_missing');
        }

        return $rendered."\n\n"
            .'Antwortvertrag: Antworte abschließend mit genau einem JSON-Dokument nach dem gebundenen Ausgabeschema'
            ." und ohne weitere Nachricht danach. Verwende exakt diese Bindungswerte:\n"
            .'schema_version: '.$this->schemaVersion($context)."\n"
            .'prompt_snapshot_hash: '.$context->promptSnapshot->hash."\n"
            .'instruction_snapshot_hash: '.$context->instructionSnapshot->hash."\n"
            .'provider_runtime_profile_hash: '.$context->runtimeProfile->hash."\n";
    }

    private function schemaVersion(AgentResultContext $context): string
    {
        return $context->role === AgentRole::IMPLEMENTATION ? 'ai6.agent.v1' : 'ai6.quality-review.v1';
    }

    /** @return list<string> */
    private function command(AgentResultContext $context, string $workspace, string $schemaPath): array
    {
        $command = [
            $this->configuration->binary, 'exec', '--json', '--ephemeral', '--ignore-user-config', '--ignore-rules',
            '--skip-git-repo-check', '--color', 'never',
            // Implementation and fix turns write only into the writable change
            // output of AI6-046, which is the working root; reviews are read-only.
            '--sandbox', $context->role === AgentRole::IMPLEMENTATION ? 'workspace-write' : 'read-only',
            '--cd', $workspace,
            '--model', $context->model,
        ];
        if ($context->effort !== 'provider_default') {
            $command[] = '--config';
            $command[] = 'model_reasoning_effort="'.$context->effort.'"';
        }
        foreach (self::CONFIG_OVERRIDES as $override) {
            $command[] = '--config';
            // /tmp of the container is outside workspace and result directory;
            // the throwaway TMPDIR in the result directory stays the only scratch.
            // The combined AGENTS.md budget of the CLI follows the one central
            // total instruction limit, so no snapshot file is ever cut or dropped.
            $command[] = str_replace('<max_instruction_total_bytes>', (string) $this->limits->maxInstructionTotalBytes, $override);
        }
        foreach (self::DISABLED_FEATURES as $feature) {
            $command[] = '--disable';
            $command[] = $feature;
        }
        $command[] = '--output-schema';
        $command[] = $schemaPath;
        $command[] = '--';
        // `-` is the documented prompt argument of the verified versions that
        // reads the instructions from standard input. A prompt on the command
        // line would be one argv element and could not carry the configured
        // maximum: Linux caps a single argument at MAX_ARG_STRLEN (128 KiB),
        // far below max_prompt_input_bytes. The bytes stay data either way.
        $command[] = '-';

        return $command;
    }

    /**
     * Version drift locks the profile before the turn: the binary must report
     * exactly the pinned line. This probe is not an exec-mode start; the one
     * exec turn follows only when the pin holds.
     *
     * @param  array<string, string>  $environment
     */
    private function assertPinnedVersion(ControlProcessRunner $runner, string $workspace, ExecutionHome $home, array $environment, Closure $heartbeat): void
    {
        $result = $this->execute($runner, [$this->configuration->binary, '--version'], $workspace, $environment, $home, $heartbeat,
            self::VERSION_PROBE_TIMEOUT_SECONDS, 'codex-cli-version');
        if (! $result->succeeded() || ! hash_equals($this->configuration->expectedVersionLine(), trim($result->output))) {
            throw new AgentExecutionException('agent_codex_version_drift');
        }
    }

    /**
     * The version-bound protection evidence of the pinned binary, taken from
     * the binary itself instead of from the presence of a flag: `features
     * list` renders the effective state of every feature the CLI knows under
     * exactly the overrides the turn passes. It starts no turn and reaches no
     * network. A switch the pin no longer offers, or an enabled feature the
     * approved surface does not name, stops the start (AGT-009, AGT-010).
     *
     * @param  array<string, string>  $environment
     */
    private function assertFeatureSurface(ControlProcessRunner $runner, string $workspace, ExecutionHome $home, array $environment, Closure $heartbeat): void
    {
        $command = [$this->configuration->binary, 'features', 'list'];
        foreach (self::DISABLED_FEATURES as $feature) {
            $command[] = '--disable';
            $command[] = $feature;
        }
        $result = $this->execute($runner, $command, $workspace, $environment, $home, $heartbeat,
            self::VERSION_PROBE_TIMEOUT_SECONDS, 'codex-cli-features');
        if (! $result->succeeded()) {
            throw new AgentExecutionException('agent_codex_feature_probe_failed');
        }
        [$enabled, $disabled] = self::featureStates($result->output, $this->redactor);
        foreach (self::DISABLED_FEATURES as $feature) {
            // A switch the pin no longer knows would be silently accepted on
            // the command line but would turn nothing off.
            if (! in_array($feature, $enabled, true) && ! in_array($feature, $disabled, true)) {
                throw new AgentExecutionException('agent_codex_feature_switch_missing');
            }
        }
        // Anything still enabled — a switch that did not take, or an extension
        // with no switch at all — must be named by the approved surface.
        if ($enabled !== self::PERMITTED_ENABLED_FEATURES) {
            throw new AgentExecutionException('agent_codex_extension_unapproved');
        }
    }

    /**
     * One `name  stage  true|false` line per feature. The bytes come from the
     * pinned binary but are provider-adjacent output: they cross the central
     * UTF-8 boundary before anything is parsed, and a line outside the fixed
     * shape ends the probe by name instead of being skipped.
     *
     * @return array{list<string>, list<string>} the enabled and the disabled names, each sorted
     */
    public static function featureStates(string $output, Redactor $redactor): array
    {
        try {
            $redactor->assertValidInput($output);
        } catch (InvalidRedactionInputException) {
            throw new AgentExecutionException('agent_codex_feature_probe_invalid');
        }
        $enabled = [];
        $disabled = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/\A([a-z0-9_]{1,64}) {2,}(?:under development|stable|experimental|deprecated|removed) {2,}(true|false)\z/D', rtrim($line), $match) !== 1) {
                throw new AgentExecutionException('agent_codex_feature_probe_invalid');
            }
            $match[2] === 'true' ? $enabled[] = $match[1] : $disabled[] = $match[1];
        }
        if ($enabled === [] && $disabled === []) {
            throw new AgentExecutionException('agent_codex_feature_probe_invalid');
        }
        sort($enabled, SORT_STRING);
        sort($disabled, SORT_STRING);

        return [$enabled, $disabled];
    }

    /**
     * Every process of this adapter goes through the one ControlProcessRunner
     * under the agent policy: argument list, environment allowlist, result
     * and artifact directories outside the tree, process-group termination.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function execute(ControlProcessRunner $runner, array $command, string $workspace, array $environment, ExecutionHome $home, Closure $heartbeat, ?int $timeoutSeconds, string $identifier, ?string $standardInput = null): ProcessResult
    {
        try {
            // A vanished result or artifact directory is refused by the request itself; that is a start failure by name.
            $running = $runner->start(new ProcessRequest($command, $workspace, self::ENVIRONMENT_ALLOWLIST, $environment,
                new RedactionContext('turn', 'turn', $identifier), timeoutSeconds: $timeoutSeconds,
                policy: ProcessPolicyName::AGENT, resultDirectory: $home->resultDirectory, artifactDirectory: $home->artifactDirectory,
                standardInput: $standardInput));
        } catch (ProcessStartRejectedException) {
            throw new AgentExecutionException('agent_process_start_rejected');
        } catch (Throwable) {
            throw new AgentExecutionException('agent_process_start_failed');
        }
        try {
            $result = $running->wait($heartbeat);
        } finally {
            if ($running->running()) {
                $running->cancel();
            }
        }
        $heartbeat();

        return $result;
    }

    /**
     * Exactly one final answer leaves the JSONL hull: the last completed
     * agent message of the one completed turn. Every line passes the central
     * UTF-8/redaction boundary before it is parsed; the answer text itself is
     * handed on unchanged for AgentResultValidator.
     *
     * An `agent_message` item is not automatically the answer. The verified
     * versions emit intermediate messages before the final one, so a message
     * counts as a result answer only when its bytes are a JSON object that
     * carries the bound `schema_version` — the one binding the answer
     * contract fixes. Two such answers in one turn, or any message after the
     * turn completed, break that contract and end as invalid_json at the
     * consumer; a message the model never meant as the answer does not.
     */
    private function answer(string $output, string $schemaVersion): AgentTurnResult
    {
        try {
            $this->redactor->assertValidInput($output);
        } catch (InvalidRedactionInputException) {
            throw new InvalidAgentResponse('agent_response_invalid_utf8');
        }
        $completedTurns = 0;
        $resultAnswers = 0;
        $afterTurn = false;
        $answer = null;
        $usage = null;
        $failed = false;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $event = $this->json->decode($line, new RedactionContext('agent', null, 'codex-event'));
            } catch (JsonDecodingException) {
                throw new InvalidAgentResponse('agent_response_hull_invalid');
            }
            $type = $event['type'] ?? null;
            if (! is_string($type)) {
                throw new InvalidAgentResponse('agent_response_hull_invalid');
            }
            if ($type === 'item.completed') {
                $item = $event['item'] ?? null;
                if (is_array($item) && ($item['type'] ?? null) === 'agent_message') {
                    $text = $item['text'] ?? null;
                    if (! is_string($text)) {
                        throw new InvalidAgentResponse('agent_response_hull_invalid');
                    }
                    $afterTurn = $afterTurn || $completedTurns > 0;
                    $resultAnswers += $this->isResultAnswer($text, $schemaVersion) ? 1 : 0;
                    $answer = $text;
                }
            } elseif ($type === 'turn.completed') {
                $completedTurns++;
                $usage = $event['usage'] ?? null;
            } elseif ($type === 'turn.failed') {
                $failed = true;
            }
        }
        if ($failed) {
            throw new AgentExecutionException('agent_codex_turn_failed');
        }
        // A turn that reached turn.completed reported real usage even when the
        // answer contract broke afterwards. Those values are carried through
        // the failure so they still reach the provider artifact; the answer
        // bytes do not, so the state stays invalid_json and nothing imports.
        [$values, $source] = $this->usage($usage);
        $reported = new AgentTurnResult('', $values, $source);
        if ($completedTurns > 1 || $resultAnswers > 1) {
            throw new InvalidAgentResponse('agent_response_multiple', $reported);
        }
        if ($afterTurn) {
            throw new InvalidAgentResponse('agent_response_after_turn', $reported);
        }
        if ($completedTurns === 0 || $answer === null) {
            throw new InvalidAgentResponse('agent_response_missing', $reported);
        }

        return new AgentTurnResult($answer, $values, $source);
    }

    /**
     * The one discriminator between an intermediate message and a complete
     * result answer: the bound schema version the answer contract demands.
     * This is no second validator — AgentResultValidator stays the only
     * authority on the answer bytes; here the value only decides whether the
     * message claims to be the answer at all.
     */
    private function isResultAnswer(string $text, string $schemaVersion): bool
    {
        // Only the decoded value decides. A textual pre-filter on the raw bytes
        // would miss a JSON string escape — "ai6.agent.v1" decodes to the
        // bound version but contains it nowhere literally — and would let a
        // second complete result answer pass as an intermediate message.
        try {
            $document = $this->json->decode($text, new RedactionContext('agent', null, 'codex-answer-shape'));
        } catch (JsonDecodingException) {
            // Unparsable bytes make no claim either. The message still reaches
            // the validator as the answer if it is the last one of the turn.
            return false;
        }

        return ($document['schema_version'] ?? null) === $schemaVersion;
    }

    /**
     * Only values the CLI itself reported, with their source; a reported zero
     * stays zero, an absent or malformed usage stays `unknown` (AGT-010).
     *
     * @return array{array<string, int>, string}
     */
    private function usage(mixed $usage): array
    {
        if (! is_array($usage)) {
            return [[], 'unknown'];
        }
        $values = [];
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_output_tokens'] as $key) {
            $value = $usage[$key] ?? null;
            if (is_int($value) && $value >= 0) {
                $values[$key] = $value;
            }
        }

        return $values === [] ? [[], 'unknown'] : [$values, self::USAGE_SOURCE];
    }

    private function regularDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_dir($path)) {
            throw new AgentExecutionException('agent_codex_workspace_unavailable');
        }

        return str_replace('\\', '/', $real);
    }
}
