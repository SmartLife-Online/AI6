<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\ProcessStartRejectedException;

/** One context-bound, disposable Copilot review session; never a persistent home. */
final readonly class NativeSessionProjection
{
    public const WRAPPER = '/usr/local/lib/ai6/native-session-wrapper.sh';

    public function __construct(
        public string $source,
        public string $target,
        public string $contextHash,
        public string $runtimeHash,
        public string $role,
    ) {}

    public static function enabled(ProviderRuntimeProfile $runtime): bool
    {
        return $runtime->version === 2 && ($runtime->permissions['native_session_state'] ?? null) === 'ephemeral_review';
    }

    public static function appliesTo(AgentResultContext $context): bool
    {
        return $context->instructionSnapshot->providerProfileAlias === GitHubCopilotCliAdapter::PROVIDER_ALIAS
            && in_array($context->role, [AgentRole::QUALITY_REVIEW, AgentRole::SECURITY_REVIEW], true)
            && self::enabled($context->runtimeProfile);
    }

    /** Validate genuine supervisor paths before constructing any mount arguments. */
    public function assertBinding(): string
    {
        $this->assertPaths();
        foreach ([$this->source, $this->target] as $path) {
            if (! is_dir($path) || is_link($path) || str_replace('\\', '/', (string) realpath($path)) !== $path) {
                throw new ProcessStartRejectedException('The native session directory is invalid.');
            }
        }
        if (scandir($this->target) !== ['.', '..']) {
            throw new ProcessStartRejectedException('The native session target is not fresh.');
        }
        $stat = stat($this->source);
        if ($stat === false) {
            throw new ProcessStartRejectedException('The native session identity is unavailable.');
        }

        return $stat['dev'].':'.$stat['ino'];
    }

    private function assertPaths(): void
    {
        $input = AgentExecutionProcessor::inputRoot();
        $output = AgentExecutionProcessor::outputRoot();
        $suffix = substr($this->target, strlen($input));
        if (! str_starts_with($this->target, $input.'/')
            || preg_match('~\A/execution-[0-9a-f]{32}/[A-Za-z0-9._-]+-[A-Za-z0-9._-]+-[0-9a-f]{16}/home/session-state\z~D', $suffix) !== 1
            || $this->source !== $output.substr($suffix, 0, -strlen('/home/session-state')).'/result/copilot-invocation/result/copilot-session-state'
            || ! in_array($this->role, ['quality_review', 'security_review'], true)
            || preg_match('/\A[0-9a-f]{64}\z/D', $this->contextHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $this->runtimeHash) !== 1) {
            throw new ProcessStartRejectedException('The native session binding is invalid.');
        }
    }

    /** Remove only this agent-created invocation, before publishing any answer. */
    public function cleanup(): void
    {
        $this->assertPaths();
        $root = dirname(dirname($this->source));
        if (! is_dir($root) || is_link($root) || str_replace('\\', '/', (string) realpath($root)) !== $root) {
            throw new CredentialProjectionException('The native session cleanup boundary is invalid.');
        }
        $remove = function (string $path) use (&$remove): void {
            if (is_link($path) || ! is_dir($path)) {
                if (! unlink($path)) {
                    throw new CredentialProjectionException('The native session cleanup failed.');
                }

                return;
            }
            if (! chmod($path, 0700)) {
                throw new CredentialProjectionException('The native session cleanup failed.');
            }
            foreach (new \FilesystemIterator($path) as $entry) {
                $remove($entry->getPathname());
            }
            if (! rmdir($path)) {
                throw new CredentialProjectionException('The native session cleanup failed.');
            }
        };
        $remove($root);
    }
}
