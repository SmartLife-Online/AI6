<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Closure;

/** Native login uses a fresh private home; only the adapter's auth file survives. */
final readonly class ProviderLogin
{
    public function __construct(private ControlProcessRunner $processes, private ProviderCredentialStore $store, private ProviderCapabilityReport $reports) {}

    /** @param Closure(string): void $hint
     * @param  Closure(): bool  $cancelled
     */
    public function login(string $alias, #[\SensitiveParameter] ?string $secret, Closure $hint, Closure $cancelled): void
    {
        ProviderOnboarding::assertAgent();
        $this->reports->boot();
        $configuration = ProviderOnboarding::configuration($alias);
        if (! $configuration->binaryPresent()) {
            throw new CredentialProjectionException('The pinned provider CLI is unavailable.');
        }
        $bytes = $this->temporary(function (string $root) use ($alias, $configuration, $secret, $hint, $cancelled): string {
            $environment = $this->environment($root, $alias);
            $version = $this->execute([$configuration->binary, '--version'], $root, $environment);
            $expected = match ($alias) {
                'codex_cli' => $configuration instanceof CodexCliConfiguration ? $configuration->expectedVersionLine() : '',
                'grok_cli' => GrokCliConfiguration::VERSION_OUTPUT,
                default => 'GitHub Copilot CLI '.GitHubCopilotCliConfiguration::TRANSPORT_VERSION.'.',
            };
            $pin = match ($alias) {
                'codex_cli' => in_array($configuration->pinnedVersion, CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS, true) ? $configuration->pinnedVersion : null,
                'grok_cli' => GrokCliConfiguration::TRANSPORT_VERSION, default => GitHubCopilotCliConfiguration::TRANSPORT_VERSION
            };
            if (! $version->succeeded() || trim($version->output) !== $expected || $configuration->pinnedVersion !== $pin) {
                throw new CredentialProjectionException('The provider login transport is not pinned.');
            }
            if ($alias === 'grok_cli') {
                if ($secret === null || preg_match('/\A[!-~]+\z/D', $secret) !== 1 || strlen($secret) > 65536
                    || ! $this->grokModelAvailable($root, $secret, 'provider_default', 'provider_default', $cancelled)) {
                    throw new CredentialProjectionException('The native Grok credential verification failed.');
                }

                return $secret;
            }
            $arguments = $alias === 'codex_cli' ? ['login', '--device-auth', '-c', 'cli_auth_credentials_store="file"'] : ['login', '--with-token'];
            if ($alias === 'github_copilot_cli' && ($secret === null || preg_match('/\A[!-~]+\z/D', $secret) !== 1 || strlen($secret) > 65536)) {
                throw new CredentialProjectionException('The provider login input is invalid.');
            }
            $result = $this->execute([$configuration->binary, ...$arguments], $root, $environment,
                $alias === 'github_copilot_cli' ? $secret."\n" : null, $alias === 'codex_cli' ? $hint : null, 300, $cancelled);
            if (! $result->succeeded()) {
                throw new CredentialProjectionException('The provider login failed or was cancelled.');
            }

            return $alias === 'codex_cli' ? AgentExecutionProcessor::readBytes($root.'/auth.json', 65536) : (string) $secret;
        });
        if ($cancelled()) {
            throw new CredentialProjectionException('The provider login was cancelled.');
        }
        $this->store->replace($alias, $bytes);
    }

    public function verifyStored(string $alias, string $generation, string $model, string $effort): bool
    {
        return in_array($effort, $this->verifiedCatalog($alias, $generation)[$model] ?? [], true);
    }

    /** One authenticated catalog per recheck; never retained across generations.
     * @return array<string, list<string>>
     */
    public function verifiedCatalog(string $alias, string $generation): array
    {
        $bytes = $this->store->locked(function () use ($alias, $generation): string {
            if ($this->store->generation($alias) !== $generation) {
                throw new CredentialProjectionException('The provider credential was rotated.');
            }

            return AgentExecutionProcessor::readBytes(ProviderOnboarding::path('store_root').'/'.$alias.'/'.ProviderOnboarding::filename($alias), 65536);
        });

        return $this->temporary(function (string $root) use ($alias, $bytes): array {
            $configuration = ProviderOnboarding::configuration($alias);
            $environment = $this->environment($root, $alias);
            if ($alias === 'codex_cli') {
                $result = $this->execute([$configuration->binary, 'login', 'status'], $root, $environment);

                if (! $result->succeeded() || ! str_contains($result->output.$result->errorOutput, 'Logged in using ChatGPT')) {
                    return [];
                }
                // The pinned manager falls back to bundled models on errors.
                // In a fresh home only a successful remote fetch creates this
                // cache; stdout or a local login status alone proves nothing.
                if (file_exists($root.'/models_cache.json')) {
                    return [];
                }
                $catalog = $this->execute([$configuration->binary, 'debug', 'models'], $root, $environment, outputLimit: 1048576);
                if (! $catalog->succeeded() || ! is_file($root.'/models_cache.json')) {
                    return [];
                }
                $cache = (new RestrictedJsonDecoder(app(Redactor::class),
                    app(ProcessPolicyRegistry::class), maximumElements: 10000))->decode(
                        AgentExecutionProcessor::readBytes($root.'/models_cache.json', 1048576), new RedactionContext('provider', null, 'models'));
                if (! is_array($cache['models'] ?? null)) {
                    return [];
                }
                $models = [];
                foreach ($cache['models'] as $entry) {
                    if (! is_array($entry) || ! is_string($entry['slug'] ?? null) || ! is_array($entry['supported_reasoning_levels'] ?? null)) {
                        return [];
                    }
                    $levels = [];
                    foreach ($entry['supported_reasoning_levels'] as $level) {
                        if (! is_array($level) || ! is_string($level['effort'] ?? null)) {
                            return [];
                        }
                        $levels[] = $level['effort'];
                    }
                    $models[$entry['slug']] = $levels;
                }

                return $models;
            }
            if ($alias === 'github_copilot_cli') {
                // The pinned CLI's bundled SDK declares models.list as an
                // authenticated catalog. Never use models.getBuiltInCatalog.
                // Only a request is sent; no session or model turn is created.
                $request = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'models.list',
                    'params' => ['gitHubToken' => $bytes]], JSON_THROW_ON_ERROR);
                $catalog = $this->execute([$configuration->binary, '--headless', '--stdio', '--no-auto-update',
                    '--no-auto-login', '--log-level', 'none'], $root, $environment,
                    'Content-Length: '.strlen($request)."\r\n\r\n".$request, outputLimit: 1048576);

                return $catalog->succeeded() ? $this->copilotCatalog($catalog->output) : [];
            }
            if ($alias === 'grok_cli') {
                return $this->grokCatalog($root, $bytes);
            }

            return [];
        }, $alias === 'codex_cli' ? $bytes : null);
    }

    private function grokModelAvailable(string $root, #[\SensitiveParameter] string $bytes, string $model, string $effort, ?Closure $cancelled = null): bool
    {
        return in_array($effort, $this->grokCatalog($root, $bytes, $cancelled)[$model] ?? [], true);
    }

    /** @return array<string, list<string>> */
    private function grokCatalog(string $root, #[\SensitiveParameter] string $bytes, ?Closure $cancelled = null): array
    {
        if (file_exists($root.'/models_cache.json')) {
            return [];
        }
        $environment = [...$this->environment($root, 'grok_cli'), 'XAI_API_KEY' => $bytes];
        $started = time();
        $catalog = $this->execute([ProviderOnboarding::configuration('grok_cli')->binary, 'models'], $root,
            $environment, cancelled: $cancelled, outputLimit: 1048576);
        // Pin 1.0.5 exits successfully with bundled models even when auth or
        // network access fails. Only a fresh native remote cache is evidence.
        if (! $catalog->succeeded() || ! is_file($root.'/models_cache.json')) {
            return [];
        }
        try {
            $cache = (new RestrictedJsonDecoder(app(Redactor::class), app(ProcessPolicyRegistry::class), maximumElements: 10000))
                ->decode(AgentExecutionProcessor::readBytes($root.'/models_cache.json', 1048576), new RedactionContext('provider', null, 'models'));
            if (($cache['grok_version'] ?? null) !== GrokCliConfiguration::TRANSPORT_VERSION
                || ($cache['auth_method'] ?? null) !== 'api_key' || ($cache['origin'] ?? null) !== 'https://api.x.ai/v1/models'
                || ! is_string($cache['fetched_at'] ?? null) || ! is_array($cache['models'] ?? null)) {
                return [];
            }
            $checked = (new \DateTimeImmutable($cache['fetched_at']))->getTimestamp();
            if ($checked < $started || $checked > time()) {
                return [];
            }
            $models = [];
            foreach ($cache['models'] as $model => $entry) {
                $info = is_array($entry) ? ($entry['info'] ?? null) : null;
                if (is_string($model) && is_array($info) && ($info['id'] ?? null) === $model && ($info['model'] ?? null) === $model
                    && ($info['supported_in_api'] ?? null) === true && ($info['hidden'] ?? null) === false) {
                    $models[$model] = ['provider_default'];
                }
            }
            if (preg_match('/^Default model: ([A-Za-z0-9][A-Za-z0-9._:-]{0,127})$/m', $catalog->output, $match) === 1
                && isset($models[$match[1]])) {
                $models['provider_default'] = ['provider_default'];
            }

            return $models;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, list<string>> */
    private function copilotCatalog(string $output): array
    {
        if (preg_match('/\AContent-Length: ([1-9][0-9]{0,6})\r\n\r\n/', $output, $header) !== 1) {
            return [];
        }
        $body = substr($output, strlen($header[0]));
        if (strlen($body) !== (int) $header[1]) {
            return [];
        }
        try {
            $response = (new RestrictedJsonDecoder(app(Redactor::class),
                app(ProcessPolicyRegistry::class), maximumElements: 10000))->decode($body, new RedactionContext('provider', null, 'models'));
        } catch (\Throwable) {
            return [];
        }
        if (($response['jsonrpc'] ?? null) !== '2.0' || ($response['id'] ?? null) !== 1 || isset($response['error'])
            || ! is_array($response['result'] ?? null) || ! is_array($response['result']['models'] ?? null)
            || ! array_is_list($response['result']['models'])) {
            return [];
        }
        $seen = [];
        $available = [];
        foreach ($response['result']['models'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null) || isset($seen[$entry['id']])) {
                return [];
            }
            $seen[$entry['id']] = true;
            $policy = $entry['policy'] ?? ['state' => 'unconfigured'];
            if (! is_array($policy) || ! in_array($policy['state'] ?? null, ['enabled', 'unconfigured'], true)) {
                continue;
            }
            $efforts = $entry['supportedReasoningEfforts'] ?? [];
            if (! is_array($efforts) || ! array_is_list($efforts) || count(array_filter($efforts, is_string(...))) !== count($efforts)) {
                return [];
            }
            $available[$entry['id']] = ['provider_default', ...array_values(array_filter($efforts, is_string(...)))];
        }

        return $available;
    }

    /** @return array<string, string> */
    private function environment(string $root, string $alias): array
    {
        $environment = ['HOME' => $root, 'TMPDIR' => $root, 'PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8'];
        $environment[match ($alias) {
            'codex_cli' => 'CODEX_HOME', 'grok_cli' => 'GROK_HOME', default => 'COPILOT_HOME'
        }] = $root;
        if ($alias === 'github_copilot_cli') {
            $environment['COPILOT_AUTO_UPDATE'] = 'false';
            $environment['COPILOT_CACHE_HOME'] = $root;
        } elseif ($alias === 'grok_cli') {
            $environment['GROK_DISABLE_AUTOUPDATER'] = '1';
            foreach (['GROK_MEMORY', 'GROK_SESSION_SEARCH', 'GROK_SESSION_REGISTRY', 'GROK_TELEMETRY_ENABLED', 'GROK_TELEMETRY_TRACE_UPLOAD'] as $name) {
                $environment[$name] = '0';
            }
        }

        return $environment;
    }

    /** @template T
     * @param  Closure(string): T  $operation
     * @return T
     */
    private function temporary(Closure $operation, #[\SensitiveParameter] ?string $sealedAuth = null): mixed
    {
        ProviderOnboarding::assertAgent();
        $root = ProviderOnboarding::path('private_root').'/login-'.bin2hex(random_bytes(16));
        if (! mkdir($root, 0700)) {
            throw new CredentialProjectionException('The private login directory is unavailable.');
        }
        try {
            if ($sealedAuth !== null) {
                $this->store->atomic($root.'/auth.json', $sealedAuth, 0400);
            }

            return $this->processes->withinAgentScope(new AgentProcessScope([], [$root], readOnlyFiles: $sealedAuth === null ? [] : [$root.'/auth.json']), fn () => $operation($root));
        } finally {
            $this->store->cleanup($root);
        }
    }

    /** @param non-empty-list<string> $arguments
     * @param  array<string, string>  $environment
     * @param  null|Closure(string): void  $hint
     * @param  null|Closure(): bool  $cancelled
     */
    private function execute(array $arguments, string $root, array $environment, #[\SensitiveParameter] ?string $input = null, ?Closure $hint = null, int $timeout = 30, ?Closure $cancelled = null, int $outputLimit = 65536): ProcessResult
    {
        $boot = $this->reports->boot();
        $running = $this->processes->start(new ProcessRequest($arguments, $root, array_keys($environment), $environment,
            new RedactionContext('provider', null, 'login'), timeoutSeconds: $timeout, outputLimitBytes: $outputLimit, standardInput: $input));
        try {
            return $running->wait(function () use ($boot, $cancelled): void {
                if ($cancelled !== null && $cancelled()) {
                    throw new CredentialProjectionException('The provider login was cancelled.');
                }
                app(ProviderCapabilityPublisher::class)->pulse($boot);
            }, observe: $hint);
        } finally {
            $running->cancel();
        }
    }
}
