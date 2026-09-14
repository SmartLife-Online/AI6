<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\ConfigurationException;

final readonly class GrokCliConfiguration
{
    public const TRANSPORT_VERSION = '1.0.5';

    public const VERSION_OUTPUT = 'grok 1.0.5 (5115b46bc9)';

    public const MAX_TURNS = 16;

    /** Native instruction basenames reported by the 1.0.5 pin. */
    public const DISCOVERY_NAMES = ['AGENTS.md', 'Agents.md', 'AGENT.md', 'CLAUDE.md', 'Claude.md', 'CLAUDE.local.md'];

    /** @param list<string> $capabilityEvidence */
    public function __construct(public string $binary, public string $pinnedVersion, public array $capabilityEvidence = [], private CanonicalJson $canonicalJson = new CanonicalJson) {}

    public static function fromConfiguredValues(CanonicalJson $canonicalJson = new CanonicalJson): self
    {
        $binary = config('ai6.grok.binary');
        $pin = config('ai6.grok.pinned_version');
        $evidence = config('ai6.grok.capability_evidence', []);
        if (! is_string($binary) || str_contains($binary, "\0") || ! is_string($pin)
            || preg_match('/\A(?:[0-9A-Za-z][0-9A-Za-z.+-]{0,63})?\z/D', $pin) !== 1
            || ! is_array($evidence) || ! array_is_list($evidence)) {
            throw new ConfigurationException('Configuration key ai6.grok is invalid.');
        }
        foreach ($evidence as $entry) {
            if (! is_string($entry) || preg_match('/\A[0-9a-f]{64}\z/D', $entry) !== 1) {
                throw new ConfigurationException('Configuration key ai6.grok.capability_evidence is invalid.');
            }
        }

        return new self($binary, $pin, $evidence, $canonicalJson);
    }

    public function binaryPresent(): bool
    {
        return $this->binary !== '' && is_file($this->binary) && ! is_link($this->binary)
            && (DIRECTORY_SEPARATOR !== '/' || is_executable($this->binary));
    }

    public function evidenceKey(ProviderRuntimeProfile $runtime, AgentRole $role, string $model, string $effort): string
    {
        return hash('sha256', "ai6.grok-capability.v1\0".$this->canonicalJson->normalizeAndEncode([
            'version' => $this->pinnedVersion, 'binary_sha256' => $this->binaryPresent() ? hash_file('sha256', $this->binary) : null,
            'platform' => PHP_OS_FAMILY, 'runtime_hash' => $runtime->hash,
            'role' => $role->value, 'model' => $model, 'effort' => $effort,
            'transport_sha256' => hash_file('sha256', __DIR__.'/GrokCliAdapter.php'),
            'home_manager_sha256' => hash_file('sha256', __DIR__.'/ExecutionHomeManager.php'),
            'sandbox_sha256' => hash_file('sha256', base_path('docker/agent-seccomp-moby-29.6.1.json')),
            'sandbox_profile_sha256' => hash('sha256', self::sandboxBytes()),
            'settings_sha256' => hash('sha256', self::settingsBytes($runtime)),
        ]));
    }

    public static function settingsBytes(ProviderRuntimeProfile $runtime): string
    {
        GrokCliAdapter::assertRuntimeProfile($runtime);

        return "[cli]\nauto_update = false\n[session]\nload_envrc = false\n[features]\ntelemetry = false\nremote_fetch = false\ncodebase_indexing = false\n[telemetry]\ntrace_upload = false\n";
    }

    public static function sandboxBytes(): string
    {
        $root = config('ai6.execution_mailboxes.agent_root');
        if (! is_string($root) || $root === '' || preg_match('/[\x00-\x1f*?\[\]{}]/', $root) === 1) {
            throw new ConfigurationException('Configuration key ai6.execution_mailboxes.agent_root is invalid for Grok sandbox.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ((! str_starts_with($root, '/') && preg_match('~\A[A-Za-z]:/~', $root) !== 1)
            || array_intersect(explode('/', $root), ['.', '..']) !== []) {
            throw new ConfigurationException('Configuration key ai6.execution_mailboxes.agent_root is invalid for Grok sandbox.');
        }
        $deny = [$root.'/execution-*/*/home/auth', $root.'/execution-*/*/runtime'];

        return "[profiles.ai6-review]\nextends = \"strict\"\nrestrict_network = true\ndeny = "
            .json_encode($deny, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * Only the compatibility cells actually exposed by the pinned inspect command.
     *
     * @return list<string>
     */
    public static function compatibilityCells(): array
    {
        $cells = ['codex.sessions'];
        foreach (['claude', 'cursor'] as $vendor) {
            foreach (['skills', 'rules', 'agents', 'mcps', 'hooks', 'sessions'] as $surface) {
                $cells[] = $vendor.'.'.$surface;
            }
        }
        sort($cells);

        return $cells;
    }

    /** @return array<string, string> */
    public static function environment(ExecutionHome $home): array
    {
        $environment = ['HOME' => $home->home, 'GROK_HOME' => $home->home, 'PATH' => '/usr/bin:/bin',
            'TMPDIR' => $home->resultDirectory, 'LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8',
            'GROK_DISABLE_AUTOUPDATER' => '1', 'GROK_MEMORY' => '0', 'GROK_SESSION_REGISTRY' => '0',
            'GROK_SESSION_SEARCH' => '0', 'GROK_TELEMETRY_ENABLED' => '0', 'GROK_TELEMETRY_TRACE_UPLOAD' => '0'];
        foreach (self::compatibilityCells() as $cell) {
            $environment['GROK_'.strtoupper(str_replace('.', '_', $cell)).'_ENABLED'] = '0';
        }

        return $environment;
    }
}
