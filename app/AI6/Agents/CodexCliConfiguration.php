<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;

/**
 * The three trusted instance values of the Codex transport (AGT-010): the
 * pinned CLI binary, the exact version it must report, and the sandbox and
 * tool-network proof taken for that version in this runtime. An empty value
 * is a valid "not set up" state that locks the codex_cli profile by name;
 * only a wrongly typed value is a configuration error.
 */
final readonly class CodexCliConfiguration
{
    public function __construct(
        public string $binary,
        public string $pinnedVersion,
        public string $sandboxProof = '',
    ) {}

    public static function fromConfiguredValues(): self
    {
        $binary = config('ai6.codex.binary');
        $pinnedVersion = config('ai6.codex.pinned_version');
        $sandboxProof = config('ai6.codex.sandbox_proof');
        if (! is_string($binary) || str_contains($binary, "\0")
            || ! is_string($pinnedVersion) || preg_match('/\A(?:[0-9A-Za-z][0-9A-Za-z.+-]{0,63})?\z/D', $pinnedVersion) !== 1
            || ! is_string($sandboxProof) || preg_match('/\A(?:[0-9A-Za-z][0-9A-Za-z.+-]{0,63}:[a-z]{1,16})?\z/D', $sandboxProof) !== 1) {
            throw new ConfigurationException('Configuration key ai6.codex must contain a binary path, a version pin and a sandbox proof.');
        }

        return new self($binary, $pinnedVersion, $sandboxProof);
    }

    public function binaryPresent(): bool
    {
        return $this->binary !== '' && is_file($this->binary) && ! is_link($this->binary)
            && (DIRECTORY_SEPARATOR !== '/' || is_executable($this->binary));
    }

    /** The exact line the pinned CLI prints for `--version`. */
    public function expectedVersionLine(): string
    {
        return 'codex-cli '.$this->pinnedVersion;
    }

    /**
     * The platform family whose sandbox backend the pinned CLI would use
     * here, derived from the running process and never from configuration —
     * so an asserted proof cannot claim a runtime it is not executing in.
     */
    public static function runtimePlatform(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => strtolower(PHP_OS_FAMILY),
        };
    }

    /**
     * Whether the configured proof binds to exactly the pinned version and
     * the platform this process actually runs on. A version bump or a
     * different runtime invalidates the proof without anyone editing it.
     */
    public function sandboxProofBinds(): bool
    {
        return $this->sandboxProof !== ''
            && hash_equals($this->pinnedVersion.':'.self::runtimePlatform(), $this->sandboxProof);
    }
}
