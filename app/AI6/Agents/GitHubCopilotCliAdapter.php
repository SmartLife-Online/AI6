<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;

/** One programmatic stdin/text transport, including security review (AI6-050). AI6-047 owns sessions, results, errors and cleanup. */
final class GitHubCopilotCliAdapter implements AgentAdapter
{
    public const PROVIDER_ALIAS = 'github_copilot_cli';

    public const READ_TOOLS = 'view,glob,grep';

    public const EXCLUDED_TOOLS = 'bash,powershell,write,edit,create,task,skill,web_fetch,web_search,store_memory,read_memories,vote_memory,delegate,read_bash,write_bash,stop_bash,sql,fetch_copilot_cli_documentation,github-mcp-server';

    public const USAGE_SOURCE = 'copilot_cli_usage_file';

    /** @var list<string> */
    public array $lastCommand = [];

    public string $lastPrompt = '';

    public function __construct(
        private readonly GitHubCopilotCliConfiguration $configuration,
        private readonly AgentInputLimits $limits,
        private readonly Redactor $redactor,
        private readonly RestrictedJsonDecoder $json,
        private readonly AgentProfileRegistry $profiles,
        private readonly CanonicalJson $canonicalJson,
        private readonly ?ControlProcessRunner $processes = null,
    ) {}

    public function result(AgentResultContext $context): string
    {
        throw new AgentExecutionException('agent_copilot_result_requires_turn');
    }

    /** @param list<string> $unreachablePaths */
    public function turn(AgentResultContext $context, ExecutionHome $home, Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
    {
        $this->lastCommand = [];
        $this->lastPrompt = '';
        $this->assertSelection($context->runtimeProfile, $context->role, $context->model, $context->effort);
        if ($context->instructionSnapshot->providerProfileAlias !== self::PROVIDER_ALIAS || $context->instructionUpdate) {
            throw new AgentExecutionException('agent_copilot_profile_mismatch');
        }
        $prompt = $this->prompt($context);
        if (strlen($prompt) > $this->limits->maxPromptInputBytes) {
            throw new AgentExecutionException('agent_prompt_input_limit_exceeded');
        }
        $this->redactor->assertValidInput($prompt);
        $this->assertHome($home, $context);
        $scratch = $home->resultDirectory.'/copilot';
        if (file_exists($scratch) || is_link($scratch) || ! mkdir($scratch, 0700) || ! mkdir($scratch.'/tmp', 0700)) {
            throw new AgentExecutionException('agent_copilot_invocation_not_fresh');
        }
        $environment = ['COPILOT_AUTO_UPDATE' => 'false', 'HOME' => $home->home, 'COPILOT_HOME' => $home->home, 'COPILOT_CACHE_HOME' => $scratch.'/cache', 'TMPDIR' => $scratch.'/tmp', 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'];
        $heartbeat();
        $this->probe($home, $context->instructionSnapshot, $environment, $heartbeat);
        $token = trim(AgentExecutionProcessor::readBytes(implode(DIRECTORY_SEPARATOR, [$home->authDirectory, 'token']), 65536));
        // Printable, non-space ASCII bytes only.
        if ($token === '' || strspn($token, implode('', range(chr(33), chr(126)))) !== strlen($token)) {
            throw new AgentExecutionException('agent_copilot_credential_projection_invalid');
        }
        // The native CLI supports this token input; no ambient login or persistent home is read.
        $environment['COPILOT_GITHUB_TOKEN'] = $token;
        $command = [$this->configuration->binary, ...self::guardArguments(),
            '--model', $context->model, '--silent', '--stream', 'off', '--output-format', $context->runtimeProfile->version === 2 ? 'json' : 'text',
            '--log-level', 'none', '--log-dir', $scratch, '--usage-output-file', $scratch.'/usage.json'];
        $this->lastCommand = $command;
        $this->lastPrompt = $prompt;
        $result = $this->execute($command, $home, $environment, $heartbeat, $prompt);
        try {
            $this->assertHome($home, $context);
        } catch (AgentExecutionException $exception) {
            throw new AgentExecutionException($exception->reason, $result->outcome, $result->exitCode, $result->errorOutput);
        }
        if (! $result->succeeded()) {
            throw new AgentExecutionException('agent_process_'.$result->outcome->value, $result->outcome, $result->exitCode, $result->errorOutput);
        }
        $usage = $this->usage($scratch.'/usage.json');

        return $this->answer($result->output, $usage, $context->runtimeProfile->version === 2);
    }

    /** Static profile check shared by the doctor and turn; never starts a process. */
    public function assertSelection(ProviderRuntimeProfile $runtime, AgentRole $role, string $model, string $effort, bool $requireEvidence = true): void
    {
        if (! in_array($role, [AgentRole::QUALITY_REVIEW, AgentRole::FINDING_VERIFICATION, AgentRole::SECURITY_REVIEW], true)) {
            throw new AgentExecutionException('agent_copilot_role_unsupported');
        }
        self::assertRuntimeProfile($runtime);
        // Model identifiers, including Claude, come from server configuration and the exact evidence key below;
        // MG-01 supplies the real model evidence, not the transport pin alone.
        // This transport has no effort override; never silently ignore a configured effort.
        if ($effort !== 'provider_default') {
            throw new AgentExecutionException('agent_copilot_selection_unsupported');
        }
        $registered = false;
        foreach ($this->profiles->configured() as $profile) {
            if ($profile->providerProfileAlias === self::PROVIDER_ALIAS && $profile->runtimeProfileId === $runtime->id
                && in_array($role, $profile->roles, true) && in_array($model, $profile->models, true) && in_array($effort, $profile->efforts, true)) {
                $registered = true;
            }
        }
        if (! $registered) {
            throw new AgentExecutionException('agent_copilot_selection_unbound');
        }
        if (! $this->configuration->binaryPresent()) {
            throw new AgentExecutionException('agent_copilot_binary_missing');
        }
        if ($this->configuration->pinnedVersion === '') {
            throw new AgentExecutionException('agent_copilot_pin_missing');
        }
        if ($this->configuration->pinnedVersion !== GitHubCopilotCliConfiguration::TRANSPORT_VERSION) {
            throw new AgentExecutionException('agent_copilot_transport_unsupported');
        }
        if ($requireEvidence && ! in_array($this->configuration->evidenceKey($runtime, $role, $model, $effort), $this->configuration->capabilityEvidence, true)) {
            throw new AgentExecutionException('agent_copilot_capability_unproven');
        }
    }

    public static function assertRuntimeProfile(ProviderRuntimeProfile $profile): void
    {
        $permissions = $profile->permissions;
        ksort($permissions);
        $expected = $profile->version === 2
            ? ['native_session_state' => 'ephemeral_review', 'network' => false, 'workspace' => 'read_only']
            : ['network' => false, 'workspace' => 'read_only'];
        if (! in_array($profile->version, [1, 2], true) || $profile->adapterFlags !== [] || $permissions !== $expected) {
            throw new AgentExecutionException('agent_copilot_runtime_unsupported');
        }
        foreach (RuntimeExtensionType::cases() as $type) {
            if (($profile->extensions[$type->value] ?? []) !== []) {
                throw new AgentExecutionException('agent_copilot_runtime_unsupported');
            }
        }
    }

    /** @return list<string> */
    public static function guardArguments(): array
    {
        // experimental=false is sealed in settings.json; the CLI flag rewrites that file.
        return ['--no-auto-update', '--no-remote-export', '--no-ask-user', '--no-color',
            '--disable-builtin-mcps', '--disallow-temp-dir', '--available-tools='.self::READ_TOOLS, '--allow-tool=read',
            '--excluded-tools='.self::EXCLUDED_TOOLS, '--deny-tool=shell,write,url,github-mcp-server'];
    }

    /**
     * Same native probes in the doctor and sealed invocation, without a model turn.
     *
     * @param  array<string, string>  $environment
     */
    public function probe(ExecutionHome $home, InstructionSnapshot $snapshot, array $environment, Closure $heartbeat, ProcessPolicyName $policy = ProcessPolicyName::AGENT): void
    {
        $version = $this->execute([$this->configuration->binary, '--version'], $home, $environment, $heartbeat, policy: $policy);
        if (! $version->succeeded() || ! $this->configuration->acceptsVersionOutput($version->output)) {
            throw new AgentExecutionException('agent_copilot_version_drift');
        }
        $surface = $this->execute([$this->configuration->binary, ...self::guardArguments(), 'plugins', 'list', '--json'], $home, $environment, $heartbeat, policy: $policy);
        if (! $surface->succeeded()) {
            throw new AgentExecutionException('agent_copilot_surface_probe_failed');
        }
        try {
            $document = $this->json->decode($surface->output, new RedactionContext('agent', null, 'copilot-surface'));
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new AgentExecutionException('agent_copilot_surface_invalid');
        }
        if (! is_array($document['plugins'] ?? null) || ! array_is_list($document['plugins']) || ($document['errors'] ?? null) !== []) {
            throw new AgentExecutionException('agent_copilot_surface_invalid');
        }
        $skills = [];
        $instructions = 0;
        foreach ($document['plugins'] as $entry) {
            if (is_array($entry) && ($entry['kind'] ?? null) === 'skill' && ($entry['source'] ?? null) === 'builtin'
                && ($entry['scope'] ?? null) === 'builtin' && ($entry['enabled'] ?? null) === false
                && in_array($entry['name'] ?? null, GitHubCopilotCliConfiguration::DISABLED_SKILLS, true)) {
                $skills[] = $entry['name'];
            } elseif (is_array($entry) && ($entry['kind'] ?? null) === 'instruction' && ($entry['source'] ?? null) === 'repository'
                && ($entry['scope'] ?? null) === 'repository' && ($entry['name'] ?? null) === 'AGENTS.md') {
                $instructions++;
            } else {
                throw new AgentExecutionException('agent_copilot_extension_unapproved');
            }
        }
        sort($skills);
        if ($skills !== GitHubCopilotCliConfiguration::DISABLED_SKILLS || $instructions > count($snapshot->entries)) {
            throw new AgentExecutionException('agent_copilot_surface_drift');
        }
    }

    public function prompt(AgentResultContext $context): string
    {
        $rendered = $context->promptSnapshot->renderedPrompts[$context->role->value] ?? null;
        if (! is_string($rendered) || $rendered === '') {
            throw new AgentExecutionException('agent_copilot_prompt_missing');
        }

        $schema = in_array($context->role, [AgentRole::QUALITY_REVIEW, AgentRole::SECURITY_REVIEW], true)
            ? "\nJSON-Schema der Antwort:\n".$this->canonicalJson->normalizeAndEncode(app(AgentResultSchema::class)->outputSchema($context))."\n"
            : '';

        return $rendered."\n\nAntwortvertrag: Genau ein JSON-Dokument, ohne Begleittext. Neue Invocation ohne native History. Alle Zeichenketten JSON-konform escapen; keine unescaped Steuerzeichen.\n"
            .'schema_version: '.($context->role === AgentRole::FINDING_VERIFICATION ? 'ai6.finding-verification.v1' : 'ai6.quality-review.v1')."\n"
            .'prompt_snapshot_hash: '.$context->promptSnapshot->hash."\n"
            .'instruction_snapshot_hash: '.$context->instructionSnapshot->hash."\n"
            .'provider_runtime_profile_hash: '.$context->runtimeProfile->hash."\n".$schema;
    }

    private function assertHome(ExecutionHome $home, AgentResultContext $context): void
    {
        if (NativeSessionProjection::appliesTo($context)) {
            $session = $home->nativeSessionProjection;
            if ($session === null || $session->contextHash !== hash('sha256', $context->toJson())
                || $session->runtimeHash !== $context->runtimeProfile->hash || $session->role !== $context->role->value) {
                throw new AgentExecutionException('agent_copilot_session_projection_unbound');
            }
            $session->assertBinding();
        }
        foreach ([$home->root, $home->home, $home->workspace, $home->authDirectory, $home->resultDirectory, $home->artifactDirectory] as $path) {
            if (! is_dir($path) || is_link($path)) {
                throw new AgentExecutionException('agent_copilot_home_missing');
            }
        }
        $expectedContext = $context->toJson();
        if (AgentExecutionProcessor::readBytes(dirname($home->runtimeConfiguration).'/turn.json', strlen($expectedContext)) !== $expectedContext) {
            throw new AgentExecutionException('agent_copilot_context_unbound');
        }
        $settings = $home->home.'/settings.json';
        if (! is_file($settings) || is_link($settings) || file_get_contents($settings) !== GitHubCopilotCliConfiguration::settingsBytes($context->runtimeProfile, $this->canonicalJson)) {
            throw new AgentExecutionException('agent_copilot_settings_unbound');
        }
        $globalState = $home->home.'/config.json';
        if (! is_file($globalState) || is_link($globalState) || file_get_contents($globalState) !== GitHubCopilotCliConfiguration::globalStateBytes($this->canonicalJson)) {
            throw new AgentExecutionException('agent_copilot_global_state_unbound');
        }
        $projection = implode(DIRECTORY_SEPARATOR, [$home->authDirectory, 'token']);
        if (! is_file($projection) || is_link($projection)) {
            throw new AgentExecutionException('agent_copilot_credential_projection_missing');
        }
        foreach (scandir($home->home) ?: [] as $name) {
            if (! in_array($name, ['.', '..', 'auth', 'settings.json', 'config.json', 'session-state'], true)) {
                throw new AgentExecutionException('agent_copilot_home_config_unapproved');
            }
        }
        foreach ([$home->authDirectory => ['token'], $home->home.'/session-state' => []] as $directory => $allowed) {
            if (! is_dir($directory) || is_link($directory) || array_diff(scandir($directory) ?: [], ['.', '..', ...$allowed]) !== []) {
                throw new AgentExecutionException('agent_copilot_native_state_unapproved');
            }
        }
        for ($ancestor = $home->root; ; $ancestor = dirname($ancestor)) {
            // Pin 1.0.83 captures ancestor Git roots, not standalone parent instructions.
            // Native home and workspace discovery remain checked separately.
            if (file_exists($ancestor.'/.git') || is_link($ancestor.'/.git')) {
                throw new AgentExecutionException('agent_copilot_parent_discovery_unbound');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
        }
        $snapshotPaths = [];
        foreach ($context->instructionSnapshot->entries as $entry) {
            $snapshotPaths[] = $entry->repositoryPath;
            $path = $home->workspace.'/'.$entry->repositoryPath;
            if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $entry->contentSha256) {
                throw new AgentExecutionException('agent_copilot_snapshot_drift');
            }
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($home->workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($home->workspace) + 1));
            if ($entry->isLink() || in_array($entry->getFilename(), ['.git', '.claude', '.copilot', '.agents', '.codex', 'CLAUDE.md', 'GEMINI.md', 'AGENTS.override.md', '.mcp.json', 'mcp.json'], true)
                || preg_match('~(?:^|/)\.github/(?:skills|agents|hooks|instructions|copilot-instructions\.md|mcp\.json|settings\.json)(?:/|$)~', $path) === 1
                || ($entry->getFilename() === 'AGENTS.md' && ! in_array($path, $snapshotPaths, true))) {
                throw new AgentExecutionException('agent_copilot_discovery_unbound');
            }
        }
    }

    /** @param list<string> $command
     * @param  array<string, string>  $environment
     */
    private function execute(array $command, ExecutionHome $home, array $environment, Closure $heartbeat, ?string $prompt = null, ProcessPolicyName $policy = ProcessPolicyName::AGENT): ProcessResult
    {
        try {
            $running = ($this->processes ?? app(ControlProcessRunner::class))->start(new ProcessRequest(
                $command, $home->workspace, array_keys($environment),
                $environment, new RedactionContext('agent', null, 'copilot-cli'), timeoutSeconds: $prompt === null ? 30 : null,
                policy: $policy, resultDirectory: $home->resultDirectory, artifactDirectory: $home->artifactDirectory,
                standardInput: $prompt,
            ));
        } catch (\Throwable) {
            throw new AgentExecutionException('agent_process_start_failed');
        }
        try {
            return $running->wait($heartbeat);
        } finally {
            if ($running->running()) {
                $running->cancel();
            }
        }
    }

    private function usage(string $path): AgentTurnResult
    {
        if (! file_exists($path)) {
            return new AgentTurnResult('');
        }
        try {
            $document = $this->json->decode(AgentExecutionProcessor::readBytes($path, 1048576), new RedactionContext('agent', null, 'copilot-usage'));
        } catch (\Throwable) {
            return new AgentTurnResult('');
        }
        $values = [];
        foreach (['totalPremiumRequestCost' => 'premium_requests', 'totalApiDurationMs' => 'api_duration_ms'] as $native => $key) {
            $value = $document[$native] ?? null;
            if ((array_key_exists($native, $document) && $value === null)
                || ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 9007199254740991)) {
                $values[$key] = $value;
            }
        }

        return new AgentTurnResult('', $values, $values === [] ? 'unknown' : self::USAGE_SOURCE);
    }

    private function answer(string $output, AgentTurnResult $usage, bool $nativeEvents = false): AgentTurnResult
    {
        try {
            $this->redactor->assertValidInput($output);
        } catch (InvalidRedactionInputException) {
            throw new InvalidAgentResponse('agent_response_invalid_utf8', $usage);
        }
        if ($nativeEvents) {
            $output = $this->nativeAnswer($output, $usage);
        }
        $answer = trim($output);
        if (preg_match('/\A```json\r?\n(.*)\r?\n```\z/sD', $answer, $match) === 1) {
            $answer = $match[1];
        }
        // Anchored extraction only. The existing decoder rejects multiple objects and duplicate keys.
        try {
            $this->json->decode($answer, new RedactionContext('agent', null, 'copilot-answer'));
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new InvalidAgentResponse('agent_response_invalid', $usage);
        }

        return new AgentTurnResult($answer, $usage->usage, $usage->usageSource);
    }

    /** Extract only one completed native message, never repair or concatenate model JSON. */
    private function nativeAnswer(string $output, AgentTurnResult $usage): string
    {
        $answer = null;
        $turnId = null;
        $ended = false;
        $result = false;
        try {
            foreach (explode("\n", trim($output)) as $line) {
                $event = $this->json->decode($line, new RedactionContext('agent', null, 'copilot-event'));
                $type = $event['type'] ?? null;
                if ($result || ! is_string($type)) {
                    throw new InvalidAgentResponse('agent_response_invalid', $usage);
                }
                if ($type === 'assistant.message') {
                    $data = $event['data'] ?? null;
                    if (! is_array($data) || (isset($data['toolRequests']) && ! is_array($data['toolRequests'])) || ! is_string($data['content'] ?? null)) {
                        throw new InvalidAgentResponse('agent_response_invalid', $usage);
                    }
                    if (($data['toolRequests'] ?? []) === [] && trim($data['content']) !== '') {
                        if ($answer !== null || ! is_string($data['turnId'] ?? null) || ($event['agentId'] ?? null) !== null) {
                            throw new InvalidAgentResponse('agent_response_invalid', $usage);
                        }
                        $answer = $data['content'];
                        $turnId = $data['turnId'];
                    }
                } elseif ($type === 'assistant.turn_end' && $answer !== null) {
                    if (($event['data']['turnId'] ?? null) !== $turnId || $ended) {
                        throw new InvalidAgentResponse('agent_response_invalid', $usage);
                    }
                    $ended = true;
                } elseif ($type === 'result') {
                    if ($answer === null || ! $ended || ($event['exitCode'] ?? null) !== 0) {
                        throw new InvalidAgentResponse('agent_response_invalid', $usage);
                    }
                    $result = true;
                }
            }
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new InvalidAgentResponse('agent_response_invalid', $usage);
        }
        if (! $result) {
            throw new InvalidAgentResponse('agent_response_invalid', $usage);
        }

        return $answer;
    }
}
