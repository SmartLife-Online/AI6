<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\InvalidAgentResponse;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderLogin;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;

/** Fixed native acceptance payload. No provider text or credentials leave the agent. */
final class CopilotNativeNegativeProbe
{
    /** @return array<string, mixed> */
    public static function run(): array
    {
        $input = config('ai6.fixture.copilot_negative');
        $context = AgentResultContext::fromJson($input['context'], app(ProviderRuntimeProfileRegistry::class), hash('sha256', $input['context']));
        $values = $input['home'];
        unset($values['nativeSessionProjection']);
        $home = app(ExecutionHomeManager::class)->withNativeSessionProjection(new ExecutionHome(...$values), $context);
        $scenario = $input['scenario'];
        if ($scenario === 'foreign_context') {
            $context = new AgentResultContext(...array_replace(get_object_vars($context), ['attempt' => 2]));
        }
        $releasedEvidence = config('ai6.copilot.capability_evidence');
        if (in_array($scenario, ['missing_evidence', 'wrong_role_evidence', 'evidence_restore'], true)) {
            $configuration = GitHubCopilotCliConfiguration::fromConfiguredValues();
            config(['ai6.copilot.capability_evidence' => $scenario === 'wrong_role_evidence'
                ? [$configuration->evidenceKey($context->runtimeProfile, AgentRole::QUALITY_REVIEW, 'auto', 'provider_default')] : []]);
            app()->forgetInstance(GitHubCopilotCliConfiguration::class);
            app()->forgetInstance(GitHubCopilotCliAdapter::class);
        }
        $adapter = app(GitHubCopilotCliAdapter::class);
        $store = app(ProviderCredentialStore::class);
        $generation = $store->generation('github_copilot_cli');
        $result = ['state' => 'not_started', 'events' => [], 'process_outcome' => null, 'native_started' => false,
            'read_tool_observed' => false, 'forbidden_tools_observed' => [], 'new_catalog_available' => false];
        try {
            if ($scenario === 'evidence_restore') {
                try {
                    $adapter->assertSelection($context->runtimeProfile, $context->role, 'auto', 'provider_default');
                    throw new \RuntimeException('The revoked candidate selection was unexpectedly accepted.');
                } catch (AgentExecutionException $error) {
                    $result['revoked_selection_denied'] = $error->reason === 'agent_copilot_capability_unproven';
                }
                config(['ai6.copilot.capability_evidence' => $releasedEvidence]);
                app()->forgetInstance(GitHubCopilotCliConfiguration::class);
                app()->forgetInstance(GitHubCopilotCliAdapter::class);
                $adapter = app(GitHubCopilotCliAdapter::class);
            }
            if ($scenario === 'missing_projection') {
                $adapter->turn($context, $home, static function (): void {});
            }
            if ($scenario === 'rotation' || $scenario === 'logout') {
                $token = AgentExecutionProcessor::readBytes(ProviderOnboarding::path('store_root').'/github_copilot_cli/token', 65536);
                $store->replace('github_copilot_cli', $scenario === 'rotation' ? $token : null);
                if ($scenario === 'rotation') {
                    $result['new_catalog_available'] = app(ProviderLogin::class)->verifyStored('github_copilot_cli', $store->generation('github_copilot_cli'), 'auto', 'provider_default');
                }
            }
            if ($scenario === 'stale') {
                $generation = str_repeat('0', 32);
            }
            $answer = $store->withProjection($home, 'github_copilot_cli', $generation,
                static function (ExecutionHome $projected) use ($adapter, $context, $scenario, &$result) {
                    // Same guards, sealed home, native probes, stdin and strict answer extraction as production.
                    $adapter->assertSelection($context->runtimeProfile, $context->role, 'auto', 'provider_default');
                    if ($scenario === 'missing_credential') {
                        $missing = new ExecutionHome($projected->root, $projected->outputRoot, $projected->workspace, $projected->home,
                            $projected->instructionOverlay, $projected->runtimeConfiguration, $projected->home.'/auth', $projected->resultDirectory,
                            $projected->artifactDirectory, $projected->patchDirectory, $projected->workspaceProjection, $projected->nativeSessionProjection);
                        $adapter->turn($context, $missing, static function (): void {});
                    }
                    (new \ReflectionMethod($adapter, 'assertHome'))->invoke($adapter, $projected, $context);
                    $scratch = $projected->resultDirectory.'/copilot';
                    if (! mkdir($scratch, 0700) || ! mkdir($scratch.'/tmp', 0700)) {
                        throw new \RuntimeException('The native acceptance scratch is not fresh.');
                    }
                    $env = ['COPILOT_AUTO_UPDATE' => 'false', 'HOME' => $projected->home, 'COPILOT_HOME' => $projected->home,
                        'COPILOT_CACHE_HOME' => $scratch.'/cache', 'TMPDIR' => $scratch.'/tmp', 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'];
                    $adapter->probe($projected, $context->instructionSnapshot, $env, static function (): void {});
                    $env['COPILOT_GITHUB_TOKEN'] = trim(AgentExecutionProcessor::readBytes($projected->authDirectory.'/token', 65536));
                    if ($scenario === 'provider_error') {
                        $env['COPILOT_GITHUB_TOKEN'] = 'invalid-synthetic-acceptance-token';
                    }
                    $command = [GitHubCopilotCliConfiguration::fromConfiguredValues()->binary, ...$adapter::guardArguments(), '--model', 'auto',
                        '--silent', '--stream', 'off', '--output-format', 'json', '--log-level', 'none', '--log-dir', $scratch, '--usage-output-file', $scratch.'/usage.json'];
                    $running = app(ControlProcessRunner::class)->start(new ProcessRequest($command, $projected->workspace, array_keys($env), $env,
                        new RedactionContext('test', null, 'copilot-negative'), timeoutSeconds: $scenario === 'timeout' ? 5 : 90,
                        policy: ProcessPolicyName::AGENT, resultDirectory: $projected->resultDirectory, artifactDirectory: $projected->artifactDirectory,
                        standardInput: $adapter->prompt($context)));
                    $observed = [];
                    $process = $running->wait(heartbeat: static function () use ($scenario, $running, &$result): void {
                        $result['native_started'] = $result['native_started'] || self::nativeProcessPresent($running->processId, $result);
                        if ($scenario === 'cancel' && $result['native_started']) {
                            $running->cancel();
                        }
                    }, observe: static function (string $bytes) use ($scenario, $running, &$observed): void {
                        $observed = self::events($bytes);
                        if ($scenario === 'cancel' && array_any($observed, fn (array $event): bool => $event['type'] === 'session.start')) {
                            $running->cancel();
                        }
                    });
                    $result['events'] = self::events($process->output);
                    $result['process_outcome'] = $process->outcome->value;
                    $result['exit_code'] = $process->exitCode;
                    $result['quiescent'] = $running->quiescent();
                    $result['stdout_sha256'] = hash('sha256', $process->output);
                    $result['stderr_sha256'] = hash('sha256', $process->errorOutput);
                    $result['native_started'] = $result['native_started'] || array_any($result['events'], fn (array $event): bool => $event['type'] === 'assistant.turn_start');
                    $tools = array_column(array_filter($result['events'], fn (array $event): bool => $event['type'] === 'tool.execution_start'), 'tool');
                    $result['read_tool_observed'] = array_intersect(['view', 'glob', 'grep', 'rg'], $tools) !== [];
                    $result['forbidden_tools_observed'] = array_values(array_intersect(explode(',', $adapter::EXCLUDED_TOOLS), $tools));
                    $result['executed_tools'] = array_values(array_unique($tools));
                    if (! $process->succeeded()) {
                        throw new AgentExecutionException('agent_process_'.$process->outcome->value, $process->outcome, $process->exitCode);
                    }
                    $usage = (new \ReflectionMethod($adapter, 'usage'))->invoke($adapter, $scratch.'/usage.json');

                    return (new \ReflectionMethod($adapter, 'answer'))->invoke($adapter, $process->output, $usage, true);
                }, $context);
            $validated = app(AgentResultValidator::class)->validate($answer->bytes, $context, new RedactionContext('test', null, 'copilot-negative'));
            $result['state'] = 'valid';
            $result['result_status'] = $validated->status->value;
            $result['usage'] = $answer->usage;
        } catch (\Throwable $error) {
            $result['state'] = $result['events'] === [] ? 'rejected' : 'failed';
            $result['exception_class'] = $error::class;
            $result['reason'] = $error instanceof AgentExecutionException || $error instanceof InvalidAgentResponse ? $error->reason : null;
            if ($error instanceof AgentResultValidationException) {
                $result['validation_error'] = $error->reason->value;
            }
            $result['error_sha256'] = hash('sha256', $error->getMessage());
        }
        $result['session_empty'] = scandir($home->home.'/session-state') === ['.', '..']
            && ! file_exists($home->nativeSessionProjection->source);
        $hostBait = config('ai6.fixture.host_bait');
        $result['host_bait_invisible'] = is_string($hostBait) && preg_match('~\A/tmp/ai6-native\.[a-zA-Z0-9]+/host-bait\z~D', $hostBait) === 1
            && ! file_exists($hostBait);
        $result['forbidden_file_absent'] = ! file_exists($home->workspace.'/forbidden.txt');
        $result['native_processes_remaining'] = 0;
        foreach ($result['observed_native_processes'] ?? [] as $pid => $started) {
            $stat = @file_get_contents('/proc/'.$pid.'/stat');
            if (is_string($stat)) {
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                if (($fields[19] ?? null) === $started && $fields[0] !== 'Z') {
                    $result['native_processes_remaining']++;
                }
            }
        }
        unset($result['observed_native_processes']);

        return $result;
    }

    /**
     * Observe descendants of the fixed native invocation. Bubblewrap creates
     * an inner session, so its child has a different process group. This is
     * observational test evidence, not a replacement for runner quiescence.
     *
     * @param  array<string, mixed>  $result
     */
    private static function nativeProcessPresent(int $group, array &$result): bool
    {
        $inventory = [];
        foreach (scandir('/proc') ?: [] as $pid) {
            if (! ctype_digit($pid)) {
                continue;
            }
            $stat = @file_get_contents('/proc/'.$pid.'/stat');
            if (is_string($stat) && preg_match('/^\d+ \((.*)\) \S+ (\d+) (\d+) /', $stat, $match) === 1) {
                $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
                $inventory[(int) $pid] = ['parent' => (int) $match[2], 'name' => $match[1], 'started' => $fields[19] ?? ''];
            }
        }
        $descendants = [$group];
        do {
            $before = count($descendants);
            foreach ($inventory as $pid => $process) {
                if (in_array($process['parent'], $descendants, true) && ! in_array($pid, $descendants, true)) {
                    $descendants[] = $pid;
                }
            }
        } while (count($descendants) !== $before);
        foreach ($descendants as $pid) {
            $comm = $inventory[$pid]['name'] ?? '';
            $name = in_array($comm, ['php', 'bwrap', 'sh', 'sha256sum', 'copilot', 'copilot-native-1', 'MainThread'], true)
                ? $comm : 'unknown:'.hash('sha256', $comm);
            $result['kernel_comm'] = array_values(array_unique([...($result['kernel_comm'] ?? []), $name]));
            if (in_array($comm, ['copilot-native-1', 'MainThread'], true)) {
                $result['observed_native_processes'][$pid] = $inventory[$pid]['started'];

                return true;
            }
        }

        return false;
    }

    /**
     * Whitelisted structural diagnostics only; unknown provider strings are hashed.
     *
     * @return list<array<string, mixed>>
     */
    private static function events(string $bytes): array
    {
        $result = [];
        foreach (explode("\n", trim($bytes)) as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $type = $event['type'] ?? '';
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $known = ['session.start', 'session.resume', 'session.info', 'session.model_change', 'session.usage_info', 'user.message',
                'assistant.turn_start', 'assistant.message', 'assistant.reasoning', 'assistant.turn_end', 'assistant.usage',
                'tool.execution_start', 'tool.execution_complete', 'session.shutdown', 'result'];
            $row = ['type' => in_array($type, $known, true) ? $type : 'unknown:'.hash('sha256', (string) $type)];
            $tool = $data['toolName'] ?? null;
            if (is_string($tool)) {
                $row['tool'] = in_array($tool, ['rg', ...explode(',', GitHubCopilotCliAdapter::READ_TOOLS), ...explode(',', GitHubCopilotCliAdapter::EXCLUDED_TOOLS)], true)
                    ? $tool : 'unknown:'.hash('sha256', $tool);
            }
            if ($type === 'assistant.message') {
                $content = $data['content'] ?? null;
                $requests = $data['toolRequests'] ?? [];
                $row['nonempty_content'] = is_string($content) && trim($content) !== '';
                $row['tool_request_count'] = is_array($requests) ? count($requests) : -1;
                $row['nested_agent'] = ($event['agentId'] ?? null) !== null;
                $row['turn_id_sha256'] = hash('sha256', (string) ($data['turnId'] ?? ''));
                if (is_string($content)) {
                    $decoded = json_decode(trim($content), true);
                    $row['content_json_valid'] = json_last_error() === JSON_ERROR_NONE;
                    $row['schema_matches'] = is_array($decoded) && ($decoded['schema_version'] ?? null) === 'ai6.quality-review.v1';
                    $row['content_sha256'] = hash('sha256', $content);
                }
            }
            if (str_starts_with($row['type'], 'unknown:')) {
                $existing = array_find_key($result, fn (array $item): bool => $item === $row || ($item['type'] === $row['type'] && ! isset($row['tool']) && ! isset($item['tool'])));
                if ($existing !== null) {
                    $result[$existing]['count'] = ($result[$existing]['count'] ?? 1) + 1;

                    continue;
                }
            }
            $result[] = $row;
        }

        return $result;
    }
}
