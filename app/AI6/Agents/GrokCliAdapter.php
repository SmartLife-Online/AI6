<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/** One prompt-file/NDJSON transport; AI6-047 owns lifecycle and domain validation. */
final class GrokCliAdapter implements AgentAdapter
{
    public const PROVIDER_ALIAS = 'grok_cli';

    public const READ_TOOLS = 'read_file,list_dir,grep';

    public const USAGE_SOURCE = 'grok_cli_result';

    /** @var list<string> */
    public array $lastCommand = [];

    public string $lastPrompt = '';

    public function __construct(
        private readonly GrokCliConfiguration $configuration,
        private readonly AgentInputLimits $limits,
        private readonly Redactor $redactor,
        private readonly RestrictedJsonDecoder $json,
        private readonly AgentProfileRegistry $profiles,
        private readonly ?ControlProcessRunner $processes = null,
    ) {}

    public function result(AgentResultContext $context): string
    {
        throw new AgentExecutionException('agent_grok_result_requires_turn');
    }

    /** @param list<string> $unreachablePaths */
    public function turn(AgentResultContext $context, ExecutionHome $home, Closure $heartbeat, array $unreachablePaths = []): AgentTurnResult
    {
        $this->lastCommand = [];
        $this->lastPrompt = '';
        $this->assertSelection($context->runtimeProfile, $context->role, $context->model, $context->effort);
        if ($context->instructionSnapshot->providerProfileAlias !== self::PROVIDER_ALIAS || $context->instructionUpdate) {
            throw new AgentExecutionException('agent_grok_profile_mismatch');
        }
        $prompt = $this->prompt($context);
        if (strlen($prompt) > $this->limits->maxPromptInputBytes) {
            throw new AgentExecutionException('agent_prompt_input_limit_exceeded');
        }
        $this->redactor->assertValidInput($prompt);
        $this->assertHome($home, $context);
        $scratch = $home->resultDirectory.'/grok';
        if (! is_dir($scratch) || is_link($scratch) || file_exists($scratch.'/started') || is_link($scratch.'/started')
            || file_exists($home->resultDirectory.'/grok-sessions') || is_link($home->resultDirectory.'/grok-sessions')) {
            throw new AgentExecutionException('agent_grok_invocation_not_fresh');
        }
        $claim = fopen($scratch.'/started', 'x');
        if ($claim === false) {
            throw new AgentExecutionException('agent_grok_invocation_not_fresh');
        }
        fclose($claim);
        if (! mkdir($home->resultDirectory.'/grok-sessions', 0700)) {
            throw new AgentExecutionException('agent_grok_session_target_unavailable');
        }
        $primary = null;
        try {
            $environment = GrokCliConfiguration::environment($home);
            $this->probe($home, $context->instructionSnapshot, $environment, $heartbeat);
            $token = trim(AgentExecutionProcessor::readBytes(implode(DIRECTORY_SEPARATOR, [$home->authDirectory, 'token']), 65536));
            if ($token === '' || strspn($token, implode('', range(chr(33), chr(126)))) !== strlen($token)) {
                throw new AgentExecutionException('agent_grok_credential_projection_invalid');
            }
            $environment['XAI_API_KEY'] = $token;
            $promptPath = $scratch.'/prompt.txt';
            if (file_put_contents($promptPath, $prompt) !== strlen($prompt) || ! chmod($promptPath, 0400)) {
                throw new AgentExecutionException('agent_grok_prompt_write_failed');
            }
            $command = [$this->configuration->binary, ...self::guardArguments(), '--cwd', $home->workspace];
            if ($context->model !== 'provider_default') {
                array_push($command, '--model', $context->model);
            }
            array_push($command, '--output-format', 'streaming-messages-json', '--prompt-file', $promptPath);
            $this->lastCommand = $command;
            $this->lastPrompt = $prompt;
            $result = $this->execute($command, $home, $environment, $heartbeat, turn: true);
            if (! $result->succeeded()) {
                throw new AgentExecutionException('agent_process_'.$result->outcome->value, $result->outcome, $result->exitCode, $result->errorOutput);
            }
            $this->assertHome($home, $context);

            return $this->answer($result->output, $home, $context->model);
        } catch (\Throwable $exception) {
            $primary = $exception;
            throw $exception;
        } finally {
            // The agent removes its own private native directories after the child stops.
            // The worker retains ownership of the output parents and the usual home cleanup.
            $sessions = $home->resultDirectory.'/grok-sessions';
            try {
                if (is_link($sessions) || ! (new Filesystem)->deleteDirectory($sessions)) {
                    throw new AgentExecutionException('agent_grok_session_cleanup_failed');
                }
            } catch (\Throwable) {
                if ($primary === null) {
                    throw new AgentExecutionException('agent_grok_session_cleanup_failed');
                }
                // Never log provider bytes or let logging replace the primary typed failure.
                try {
                    Log::warning('agent_grok_session_cleanup_failed');
                } catch (\Throwable) {
                    // The primary failure remains authoritative if the logger is unavailable.
                }
            }
        }
    }

    public function assertSelection(ProviderRuntimeProfile $runtime, AgentRole $role, string $model, string $effort, bool $requireEvidence = true): void
    {
        if (! in_array($role, [AgentRole::QUALITY_REVIEW, AgentRole::FINDING_VERIFICATION], true)) {
            throw new AgentExecutionException('agent_grok_role_unsupported');
        }
        self::assertRuntimeProfile($runtime);
        if ($effort !== 'provider_default') {
            throw new AgentExecutionException('agent_grok_selection_unsupported');
        }
        $registered = false;
        foreach ($this->profiles->all() as $profile) {
            if ($profile->providerProfileAlias === self::PROVIDER_ALIAS && $profile->runtimeProfileId === $runtime->id
                && in_array($role, $profile->roles, true) && in_array($model, $profile->models, true) && in_array($effort, $profile->efforts, true)) {
                $registered = true;
            }
        }
        if (! $registered) {
            throw new AgentExecutionException('agent_grok_selection_unbound');
        }
        if (! $this->configuration->binaryPresent()) {
            throw new AgentExecutionException('agent_grok_binary_missing');
        }
        if ($this->configuration->pinnedVersion === '') {
            throw new AgentExecutionException('agent_grok_pin_missing');
        }
        if ($this->configuration->pinnedVersion !== GrokCliConfiguration::TRANSPORT_VERSION) {
            throw new AgentExecutionException('agent_grok_transport_unsupported');
        }
        if ($requireEvidence && ! in_array($this->configuration->evidenceKey($runtime, $role, $model, $effort), $this->configuration->capabilityEvidence, true)) {
            throw new AgentExecutionException('agent_grok_capability_unproven');
        }
    }

    public static function assertRuntimeProfile(ProviderRuntimeProfile $profile): void
    {
        $permissions = $profile->permissions;
        ksort($permissions);
        if ($profile->adapterFlags !== [] || $permissions !== ['network' => false, 'workspace' => 'read_only']) {
            throw new AgentExecutionException('agent_grok_runtime_unsupported');
        }
        foreach (RuntimeExtensionType::cases() as $type) {
            if (($profile->extensions[$type->value] ?? []) !== []) {
                throw new AgentExecutionException('agent_grok_runtime_unsupported');
            }
        }
    }

    /** @return list<string> */
    public static function guardArguments(): array
    {
        return ['--no-auto-update', '--no-memory', '--no-subagents', '--no-plan', '--disable-web-search',
            '--max-turns', (string) GrokCliConfiguration::MAX_TURNS, '--verbatim',
            '--tools', self::READ_TOOLS, '--disallowed-tools', 'search_tool,use_tool,Agent',
            '--permission-mode', 'dontAsk', '--sandbox', 'ai6-review'];
    }

    /** Credential-free Doctor probe of the exact turn guards, never a provider turn. */
    public function probeSandbox(ExecutionHome $home, string $model): void
    {
        $prompt = $home->resultDirectory.'/doctor-prompt.txt';
        if (file_put_contents($prompt, 'AI6 credential-free sandbox preparation probe.') === false || ! chmod($prompt, 0400)
            || ! mkdir($home->resultDirectory.'/grok-sessions', 0700)) {
            throw new AgentExecutionException('agent_grok_probe_directory_unavailable');
        }
        $command = [$this->configuration->binary, ...self::guardArguments(), '--cwd', $home->workspace];
        if ($model !== 'provider_default') {
            array_push($command, '--model', $model);
        }
        array_push($command, '--output-format', 'streaming-messages-json', '--prompt-file', $prompt);
        $result = $this->execute($command, $home, GrokCliConfiguration::environment($home), static function (): void {}, ProcessPolicyName::CONTROL);
        // Classification uses only fixed native diagnostics; provider bytes never reach Doctor output.
        $diagnostics = $result->errorOutput;
        if (str_contains($diagnostics, 'bwrap:') && (str_contains($diagnostics, 'namespace') || str_contains($diagnostics, 'Operation not permitted'))) {
            throw new AgentExecutionException('agent_grok_sandbox_role_unverifiable');
        }
        $inits = 0;
        $authErrors = 0;
        try {
            foreach (explode("\n", trim($result->output)) as $line) {
                $event = $this->json->decode($line, new RedactionContext('agent', null, 'grok-sandbox-probe'));
                if (($event['type'] ?? null) === 'system' && ($event['subtype'] ?? null) === 'init' && $authErrors === 0) {
                    $inits++;
                } elseif (($event['type'] ?? null) === 'result' && ($event['subtype'] ?? null) === 'error_during_execution'
                    && ($event['is_error'] ?? null) === true && ($event['num_turns'] ?? null) === 0
                    && is_array($event['errors'] ?? null) && count($event['errors']) === 1
                    && is_string($event['errors'][0] ?? null) && str_starts_with($event['errors'][0], 'Not signed in.')) {
                    $authErrors++;
                } else {
                    throw new AgentExecutionException('agent_grok_sandbox_unprepared');
                }
            }
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new AgentExecutionException('agent_grok_sandbox_unprepared');
        }
        if ($result->outcome !== ProcessOutcome::FAILED || $result->exitCode !== 1 || $inits !== 1 || $authErrors !== 1
            || ! str_starts_with(trim($diagnostics), 'Error: Not signed in.')) {
            throw new AgentExecutionException('agent_grok_sandbox_unprepared');
        }
    }

    /** @param array<string, string> $environment */
    public function probe(ExecutionHome $home, InstructionSnapshot $snapshot, array $environment, Closure $heartbeat, ProcessPolicyName $policy = ProcessPolicyName::AGENT): void
    {
        $version = $this->execute([$this->configuration->binary, '--no-auto-update', '--version'], $home, $environment, $heartbeat, $policy);
        if (! $version->succeeded() || trim($version->output) !== GrokCliConfiguration::VERSION_OUTPUT) {
            throw new AgentExecutionException('agent_grok_version_drift');
        }
        $surface = $this->execute([$this->configuration->binary, '--no-auto-update', 'inspect', '--json'], $home, $environment, $heartbeat, $policy);
        if (! $surface->succeeded()) {
            throw new AgentExecutionException('agent_grok_surface_probe_failed');
        }
        $this->assertSurface($surface->output, $home, $snapshot);
    }

    private function assertSurface(string $output, ExecutionHome $home, InstructionSnapshot $snapshot): void
    {
        try {
            $document = $this->json->decode($output, new RedactionContext('agent', null, 'grok-surface'));
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new AgentExecutionException('agent_grok_surface_invalid');
        }
        foreach (['hooks', 'skills', 'plugins', 'marketplaces', 'mcpServers', 'lspServers'] as $key) {
            if (($document[$key] ?? null) !== []) {
                throw new AgentExecutionException('agent_grok_extension_unapproved');
            }
        }
        $expectedLayers = [['role' => 'user', 'path' => $this->redactor->redact($home->home.'/config.toml', new RedactionContext('agent', null, 'grok-surface'))->text]];
        if (($document['grokVersion'] ?? null) !== GrokCliConfiguration::TRANSPORT_VERSION
            || ($document['cwd'] ?? null) !== $this->redactor->redact($home->workspace, new RedactionContext('agent', null, 'grok-surface'))->text || ! array_key_exists('projectRoot', $document) || $document['projectRoot'] !== null
            || ($document['configSources']['layers'] ?? null) !== $expectedLayers
            || ($document['permissions']['sources'] ?? null) !== [] || ($document['permissions']['loaded'] ?? null) !== 0
            || ($document['permissions']['skipped'] ?? null) !== [] || ($document['permissions']['mcpServerAllowlist'] ?? null) !== []
            || ($document['permissions']['marketplaceAllowlist'] ?? null) !== [] || ($document['permissions']['managedSettingsExists'] ?? null) !== false
            || ($document['permissions']['managedSettingsActive'] ?? null) !== false
            || ($document['externalCompat']['remoteSettingsLoaded'] ?? null) !== false) {
            throw new AgentExecutionException('agent_grok_surface_drift');
        }
        $cells = [];
        foreach ($document['externalCompat']['cells'] ?? [] as $cell) {
            if (! is_array($cell) || ! is_string($cell['vendor'] ?? null) || ! is_string($cell['surface'] ?? null)
                || ($cell['enabled'] ?? null) !== false || ($cell['source'] ?? null) !== 'env') {
                throw new AgentExecutionException('agent_grok_extension_unapproved');
            }
            $cells[] = $cell['vendor'].'.'.$cell['surface'];
        }
        sort($cells);
        $agents = [];
        foreach ($document['agents'] ?? [] as $agent) {
            if (! is_array($agent) || ($agent['source'] ?? null) !== ['type' => 'builtin'] || ! is_string($agent['name'] ?? null)) {
                throw new AgentExecutionException('agent_grok_extension_unapproved');
            }
            $agents[] = $agent['name'];
        }
        sort($agents);
        if ($cells !== GrokCliConfiguration::compatibilityCells() || $agents !== ['explore', 'general-purpose', 'plan']) {
            throw new AgentExecutionException('agent_grok_surface_drift');
        }
        $instructions = [];
        foreach ($document['projectInstructions'] ?? [] as $instruction) {
            if (! is_array($instruction) || ! is_string($instruction['path'] ?? null) || ($instruction['fileType'] ?? null) !== 'agents_md') {
                throw new AgentExecutionException('agent_grok_discovery_unbound');
            }
            $instructions[] = $instruction['path'];
        }
        // inspect reports instructions applicable at cwd; nested snapshots are checked on disk below.
        $expected = [];
        foreach ($snapshot->entries as $entry) {
            if ($entry->repositoryPath === 'AGENTS.md') {
                $expected[] = $this->redactor->redact($home->workspace.'/AGENTS.md', new RedactionContext('agent', null, 'grok-surface'))->text;
            }
        }
        sort($instructions);
        sort($expected);
        if ($instructions !== $expected) {
            throw new AgentExecutionException('agent_grok_discovery_unbound');
        }
    }

    public function prompt(AgentResultContext $context): string
    {
        $rendered = $context->promptSnapshot->renderedPrompts[$context->role->value] ?? null;
        if (! is_string($rendered) || $rendered === '') {
            throw new AgentExecutionException('agent_grok_prompt_missing');
        }

        return $rendered."\n\nAntwortvertrag: Genau ein JSON-Dokument, ohne Begleittext. Neue Invocation ohne native History.\n"
            .'schema_version: '.($context->role === AgentRole::FINDING_VERIFICATION ? 'ai6.finding-verification.v1' : 'ai6.quality-review.v1')."\n"
            .'prompt_snapshot_hash: '.$context->promptSnapshot->hash."\n"
            .'instruction_snapshot_hash: '.$context->instructionSnapshot->hash."\n"
            .'provider_runtime_profile_hash: '.$context->runtimeProfile->hash."\n";
    }

    private function assertHome(ExecutionHome $home, AgentResultContext $context): void
    {
        foreach ([$home->root, $home->home, $home->workspace, $home->authDirectory, $home->resultDirectory, $home->artifactDirectory, $home->home.'/hooks'] as $path) {
            if (! is_dir($path) || is_link($path)) {
                throw new AgentExecutionException('agent_grok_home_missing');
            }
        }
        if (! is_link($home->home.'/sessions') || readlink($home->home.'/sessions') !== $home->resultDirectory.'/grok-sessions') {
            throw new AgentExecutionException('agent_grok_session_target_unbound');
        }
        $expectedContext = $context->toJson();
        if (AgentExecutionProcessor::readBytes(dirname($home->runtimeConfiguration).'/turn.json', strlen($expectedContext)) !== $expectedContext) {
            throw new AgentExecutionException('agent_grok_context_unbound');
        }
        foreach (['config.toml' => GrokCliConfiguration::settingsBytes($context->runtimeProfile),
            'sandbox.toml' => GrokCliConfiguration::sandboxBytes(), 'hooks-paths' => ''] as $name => $bytes) {
            if (! is_file($home->home.'/'.$name) || is_link($home->home.'/'.$name) || file_get_contents($home->home.'/'.$name) !== $bytes) {
                throw new AgentExecutionException('agent_grok_settings_unbound');
            }
        }
        $credential = implode(DIRECTORY_SEPARATOR, [$home->authDirectory, 'token']);
        if (! is_file($credential) || is_link($credential)) {
            throw new AgentExecutionException('agent_grok_credential_projection_missing');
        }
        foreach ([$home->home => ['auth', 'config.toml', 'sandbox.toml', 'hooks', 'hooks-paths', 'sessions'], $home->authDirectory => ['token'], $home->home.'/hooks' => []] as $directory => $allowed) {
            if (array_diff(scandir($directory) ?: [], ['.', '..', ...$allowed]) !== []) {
                throw new AgentExecutionException('agent_grok_home_config_unapproved');
            }
        }
        $snapshots = [];
        foreach ($context->instructionSnapshot->entries as $entry) {
            $snapshots[] = $entry->repositoryPath;
            $path = $home->workspace.'/'.$entry->repositoryPath;
            if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $entry->contentSha256) {
                throw new AgentExecutionException('agent_grok_snapshot_drift');
            }
        }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($home->workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            $path = str_replace('\\', '/', substr($entry->getPathname(), strlen($home->workspace) + 1));
            if ($entry->isLink() || in_array($entry->getFilename(), ['.git', '.grok', '.claude', '.cursor', '.agents', '.codex', '.envrc', '.mcp.json', 'mcp.json', 'AGENTS.override.md'], true)
                || (in_array($entry->getFilename(), GrokCliConfiguration::DISCOVERY_NAMES, true)
                    && ($entry->getFilename() !== 'AGENTS.md' || ! in_array($path, $snapshots, true)))) {
                throw new AgentExecutionException('agent_grok_discovery_unbound');
            }
        }
    }

    /** @param list<string> $command
     * @param  array<string, string>  $environment
     */
    private function execute(array $command, ExecutionHome $home, array $environment, Closure $heartbeat, ProcessPolicyName $policy = ProcessPolicyName::AGENT, bool $turn = false): ProcessResult
    {
        try {
            $running = ($this->processes ?? app(ControlProcessRunner::class))->start(new ProcessRequest(
                $command, $home->workspace, array_keys($environment), $environment, new RedactionContext('agent', null, 'grok-cli'),
                timeoutSeconds: $turn ? null : 30, policy: $policy, resultDirectory: $home->resultDirectory, artifactDirectory: $home->artifactDirectory,
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

    private function answer(string $output, ExecutionHome $home, string $model): AgentTurnResult
    {
        $usage = new AgentTurnResult('');
        $answer = null;
        $finals = 0;
        $invalid = false;
        $inits = 0;
        $reason = null;
        // Keep reading after malformed lines so already reported usage survives invalid_json.
        foreach (explode("\n", $output) as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $this->redactor->assertValidInput($line);
                $event = $this->json->decode($line, new RedactionContext('agent', null, 'grok-event'));
            } catch (JsonDecodingException|InvalidRedactionInputException) {
                $invalid = true;

                continue;
            }
            if (! is_string($event['type'] ?? null)) {
                $invalid = true;
            }
            if (($event['type'] ?? null) === 'system' && ($event['subtype'] ?? null) === 'init') {
                $inits++;
                if ($inits !== 1 || $finals !== 0) {
                    $reason ??= 'agent_grok_init_invalid';
                }
                if ($model !== 'provider_default' && ($event['model'] ?? null) !== $model) {
                    $reason ??= 'agent_grok_init_surface_drift';
                }
                foreach (['tools' => ['read_file', 'list_dir', 'grep'], 'mcp_servers' => [], 'skills' => [],
                    'permissionMode' => 'dontAsk', 'cwd' => $this->redactor->redact($home->workspace, new RedactionContext('agent', null, 'grok-event'))->text] as $key => $expected) {
                    if (($event[$key] ?? null) !== $expected) {
                        $reason ??= 'agent_grok_init_surface_drift';
                    }
                }
            }
            if (($event['type'] ?? null) !== 'result') {
                continue;
            }
            $finals++;
            if ($inits !== 1) {
                $reason ??= 'agent_grok_init_invalid';
            }
            if (($event['usage']['server_tool_use']['web_search_requests'] ?? null) !== 0) {
                $reason ??= 'agent_grok_web_search_unbound';
            }
            if (($event['subtype'] ?? null) === 'error_max_turns') {
                $reason ??= 'agent_grok_max_turns_exceeded';
            }
            $values = [];
            foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'] as $key) {
                if (is_array($event['usage'] ?? null) && array_key_exists($key, $event['usage'])) {
                    $values[$key] = $event['usage'][$key];
                }
            }
            // This format synthesizes zero for unknown usage/cost and undercounts API time.
            // Validate supplied scalars before discarding ambiguous zero/null-only buckets.
            try {
                if ($values !== []) {
                    new AgentTurnResult('', $values, self::USAGE_SOURCE);
                    if (array_filter($values, static fn ($value): bool => $value !== null && $value > 0) === []) {
                        $values = [];
                    }
                }
                if (array_key_exists('total_cost_usd', $event)) {
                    new AgentTurnResult('', ['cost_usd' => $event['total_cost_usd']], self::USAGE_SOURCE);
                    if ($event['total_cost_usd'] > 0) {
                        $values['cost_usd'] = $event['total_cost_usd'];
                    }
                }
                if (array_key_exists('num_turns', $event)) {
                    if (! is_int($event['num_turns']) || $event['num_turns'] < 0) {
                        throw new AgentExecutionException('agent_usage_invalid');
                    }
                    $values['num_turns'] = $event['num_turns'];
                }
                if ($values !== []) {
                    $usage = new AgentTurnResult('', array_replace($usage->usage, $values), self::USAGE_SOURCE);
                }
            } catch (AgentExecutionException) {
                $invalid = true;
            }
            if (($event['subtype'] ?? null) !== 'success' || ($event['is_error'] ?? null) !== false
                || ! is_string($event['result'] ?? null) || $event['result'] === '') {
                $invalid = true;
            } else {
                $answer = $event['result'];
            }
        }
        if ($invalid || $finals !== 1 || $answer === null || $inits !== 1 || $reason !== null) {
            throw new InvalidAgentResponse($reason ?? ($inits !== 1 ? 'agent_grok_init_invalid' : 'agent_response_invalid'), $usage);
        }

        try {
            $this->json->decode($answer, new RedactionContext('agent', null, 'grok-answer'));
        } catch (JsonDecodingException|InvalidRedactionInputException) {
            throw new InvalidAgentResponse('agent_response_invalid', $usage);
        }

        return new AgentTurnResult($answer, $usage->usage, $usage->usageSource);
    }
}
