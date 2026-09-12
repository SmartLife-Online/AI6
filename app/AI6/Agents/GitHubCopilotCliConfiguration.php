<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\ConfigurationException;

/** Trusted instance configuration; no credential value or automatic capability approval. */
final readonly class GitHubCopilotCliConfiguration
{
    public const TRANSPORT_VERSION = '1.0.83';

    public const DISABLED_SKILLS = ['customize-cloud-agent', 'discover-resources', 'github-pr-media'];

    /** @param list<string> $capabilityEvidence */
    public function __construct(public string $binary, public string $pinnedVersion, public array $capabilityEvidence = [], private CanonicalJson $canonicalJson = new CanonicalJson) {}

    public static function fromConfiguredValues(CanonicalJson $canonicalJson = new CanonicalJson): self
    {
        $binary = config('ai6.copilot.binary');
        $pin = config('ai6.copilot.pinned_version');
        $evidence = config('ai6.copilot.capability_evidence', []);
        if (! is_string($binary) || str_contains($binary, "\0") || ! is_string($pin)
            || preg_match('/\A(?:[0-9A-Za-z][0-9A-Za-z.+-]{0,63})?\z/D', $pin) !== 1
            || ! is_array($evidence) || ! array_is_list($evidence)) {
            throw new ConfigurationException('Configuration key ai6.copilot is invalid.');
        }
        foreach ($evidence as $entry) {
            if (! is_string($entry) || preg_match('/\A[0-9a-f]{64}\z/D', $entry) !== 1) {
                throw new ConfigurationException('Configuration key ai6.copilot.capability_evidence is invalid.');
            }
        }

        return new self($binary, $pin, $evidence, $canonicalJson);
    }

    public function binaryPresent(): bool
    {
        return $this->binary !== '' && is_file($this->binary) && ! is_link($this->binary)
            && (DIRECTORY_SEPARATOR !== '/' || is_executable($this->binary));
    }

    /** A binding identifier is not proof by itself; only a human supplies approved entries. */
    public function evidenceKey(ProviderRuntimeProfile $runtime, AgentRole $role, string $model, string $effort): string
    {
        return hash('sha256', "ai6.copilot-capability.v1\0".$this->canonicalJson->normalizeAndEncode([
            'version' => $this->pinnedVersion, 'binary_sha256' => $this->binaryPresent() ? hash_file('sha256', $this->binary) : null,
            'platform' => PHP_OS_FAMILY, 'runtime_hash' => $runtime->hash,
            'role' => $role->value, 'model' => $model, 'effort' => $effort,
            'transport_sha256' => hash_file('sha256', __DIR__.'/GitHubCopilotCliAdapter.php'),
            'settings_sha256' => hash('sha256', self::settingsBytes($runtime, $this->canonicalJson)),
        ]));
    }

    /** Native settings required to disable built-in skills, hooks, memory and IDE discovery. */
    public static function settingsBytes(ProviderRuntimeProfile $runtime, CanonicalJson $canonicalJson): string
    {
        GitHubCopilotCliAdapter::assertRuntimeProfile($runtime);

        return $canonicalJson->normalizeAndEncode([
            'autoUpdate' => false, 'experimental' => false, 'disableAllHooks' => true,
            'disabledSkills' => self::DISABLED_SKILLS, 'memory' => false,
            'customAgents' => ['defaultLocalOnly' => true], 'ide' => ['autoConnect' => false],
            'renderMarkdown' => false, 'updateTerminalTitle' => false, 'terminalProgress' => false,
        ])."\n";
    }
}
