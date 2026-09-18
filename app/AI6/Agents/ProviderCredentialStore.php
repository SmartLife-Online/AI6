<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Redaction\Redactor;
use Closure;
use FilesystemIterator;

/** Agent-owned credentials; shared channels contain only random generations. */
final readonly class ProviderCredentialStore
{
    public function __construct(private ProviderCapabilityReport $reports, private Redactor $redactor) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function locked(Closure $operation): mixed
    {
        ProviderOnboarding::assertAgent();
        $root = ProviderOnboarding::path('store_root');
        $this->assertRoot($root);
        $path = $root.'/.lock';
        if (is_link($path)) {
            throw new CredentialProjectionException('The provider store lock is invalid.');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false || ! chmod($path, 0600) || ! flock($lock, LOCK_EX)) {
            throw new CredentialProjectionException('The provider store lock is unavailable.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function generation(string $alias): string
    {
        ProviderOnboarding::assertAgent();
        ProviderOnboarding::filename($alias);
        $value = trim(AgentExecutionProcessor::readBytes(ProviderOnboarding::path('store_root').'/'.$alias.'/generation', 64));
        if (preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new CredentialProjectionException('The provider store generation is invalid.');
        }

        return $value;
    }

    /** Called before the new boot publishes its first heartbeat. */
    public function recover(): void
    {
        $this->locked(function (): void {
            $root = ProviderOnboarding::path('private_root');
            $this->assertRoot($root);
            foreach (new FilesystemIterator($root) as $entry) {
                $this->cleanup(str_replace('\\', '/', $entry->getPathname()));
            }
        });
    }

    /** Commit only a completed login; null means logout. Revocation precedes all store changes. */
    public function replace(string $alias, #[\SensitiveParameter] ?string $bytes): void
    {
        ProviderOnboarding::assertAgent();
        $file = ProviderOnboarding::filename($alias);
        if ($bytes !== null) {
            $this->validate($alias, $bytes);
        }
        $this->locked(function () use ($alias, $file, $bytes): void {
            $generation = bin2hex(random_bytes(16));
            $this->publish($alias, $generation, [], time(), $this->reports->boot());
            $directory = ProviderOnboarding::path('store_root').'/'.$alias;
            if (! is_dir($directory) && ! mkdir($directory, 0700)) {
                throw new CredentialProjectionException('The provider store is unavailable.');
            }
            $this->assertRoot($directory);
            if ($bytes === null) {
                if (file_exists($directory.'/'.$file) && ! unlink($directory.'/'.$file)) {
                    throw new CredentialProjectionException('The provider logout failed.');
                }
            } else {
                $this->atomic($directory.'/'.$file, $bytes, 0600);
            }
            $this->atomic($directory.'/generation', $generation, 0600);
        });
    }

    /** @param list<array<string, string>> $rows Caller holds the store lock. */
    public function publish(string $alias, string $generation, array $rows, int $checkedAt, string $boot): void
    {
        ProviderOnboarding::assertAgent();
        ProviderOnboarding::filename($alias);
        $root = ProviderOnboarding::path('report_root');
        $this->assertRoot($root);
        $bytes = json_encode(['schema' => 'ai6.provider-capability.v1', 'alias' => $alias,
            'generation' => $generation, 'boot_id' => $boot, 'checked_at' => $checkedAt, 'rows' => $rows], JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 65536) {
            throw new CredentialProjectionException('The provider report exceeds its limit.');
        }
        $this->atomic($root.'/'.$alias.'.json', $bytes, 0644);
    }

    /**
     * Materialization and rotation use the same lock; the process heartbeat checks
     * the generation after the lock is released and cancels the whole invocation.
     *
     * @template T
     *
     * @param  Closure(ExecutionHome): T  $operation
     * @return T
     */
    public function withProjection(ExecutionHome $home, string $alias, string $generation, Closure $operation): mixed
    {
        $projection = $this->locked(function () use ($alias, $generation): string {
            if ($this->generation($alias) !== $generation || $this->reports->generation($alias, false) !== $generation) {
                throw new CredentialProjectionException('The provider projection generation was revoked.');
            }
            $file = ProviderOnboarding::filename($alias);
            $source = ProviderOnboarding::path('store_root').'/'.$alias.'/'.$file;
            if (! is_file($source) || is_link($source)) {
                throw new AgentExecutionException(match ($alias) {
                    'codex_cli' => 'agent_codex_credential_projection_missing',
                    'grok_cli' => 'agent_grok_credential_projection_missing',
                    default => 'agent_copilot_credential_projection_missing',
                });
            }
            $bytes = AgentExecutionProcessor::readBytes($source, 65536);
            $this->validate($alias, $bytes);
            $root = ProviderOnboarding::path('private_root');
            $this->assertRoot($root);
            $directory = $root.'/projection-'.bin2hex(random_bytes(16));
            if (! mkdir($directory, 0700)) {
                throw new CredentialProjectionException('The provider projection is unavailable.');
            }
            try {
                $this->atomic($directory.'/'.$file, $bytes, 0400);
                if (! chmod($directory, 0500)) {
                    throw new CredentialProjectionException('The provider projection could not be sealed.');
                }
            } catch (\Throwable $exception) {
                $this->cleanup($directory);
                throw $exception;
            }

            return $directory;
        });
        try {
            $projected = new ExecutionHome($home->root, $home->outputRoot, $home->workspace, $home->home,
                $home->instructionOverlay, $home->runtimeConfiguration, $projection, $home->resultDirectory,
                $home->artifactDirectory, $home->patchDirectory, $home->workspaceProjection);

            return app(ControlProcessRunner::class)->withinAgentScope(
                new AgentProcessScope([$home->root, $projection], [$home->outputRoot], function () use ($alias, $generation): void {
                    if ($this->reports->generation($alias, false) !== $generation) {
                        throw new CredentialProjectionException('The provider projection generation was revoked.');
                    }
                }),
                fn () => $operation($projected),
            );
        } finally {
            $this->cleanup($projection);
        }
    }

    public function atomic(string $path, #[\SensitiveParameter] string $bytes, int $mode): void
    {
        ProviderOnboarding::assertAgent();
        $this->assertRoot(dirname($path));
        if (is_link($path)) {
            throw new CredentialProjectionException('The provider destination is invalid.');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new CredentialProjectionException('The provider publication failed.');
        }
        try {
            if (! chmod($temporary, $mode) || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new CredentialProjectionException('The provider publication failed.');
            }
            fclose($stream);
            if (! rename($temporary, $path)) {
                throw new CredentialProjectionException('The provider publication failed.');
            }
            if (PHP_OS_FAMILY === 'Linux') {
                $directory = fopen(dirname($path), 'r');
                if ($directory === false) {
                    throw new CredentialProjectionException('The provider publication directory is unavailable.');
                }
                try {
                    if (! fsync($directory)) {
                        throw new CredentialProjectionException('The provider publication directory is not durable.');
                    }
                } finally {
                    fclose($directory);
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function validate(string $alias, #[\SensitiveParameter] string $bytes): void
    {
        $this->redactor->assertValidInput($bytes);
        if ($bytes === '' || strlen($bytes) > 65536 || ($alias !== 'codex_cli' && preg_match('/\A[!-~]+\z/D', $bytes) !== 1)) {
            throw new CredentialProjectionException('The provider credential format is invalid.');
        }
    }

    private function assertRoot(string $root): void
    {
        if (! is_dir($root) || is_link($root) || str_replace('\\', '/', (string) realpath($root)) !== $root) {
            throw new CredentialProjectionException('The provider directory is invalid.');
        }
    }

    /** Only private ephemeral children can ever be recursively removed. */
    public function cleanup(string $directory): void
    {
        ProviderOnboarding::assertAgent();
        $root = ProviderOnboarding::path('private_root');
        if (dirname($directory) !== $root || preg_match('/\A(?:projection|login|probe)-[0-9a-f]{32}\z/D', basename($directory)) !== 1) {
            throw new CredentialProjectionException('The provider cleanup boundary is invalid.');
        }
        $this->assertRoot($directory);
        $remove = function (string $path) use (&$remove): void {
            if (is_link($path) || ! is_dir($path)) {
                if (! is_link($path) && ! chmod($path, 0600)) {
                    throw new CredentialProjectionException('The provider cleanup failed.');
                }
                if (! unlink($path)) {
                    throw new CredentialProjectionException('The provider cleanup failed.');
                }

                return;
            }
            chmod($path, 0700);
            foreach (new FilesystemIterator($path) as $entry) {
                $remove($entry->getPathname());
            }
            if (! rmdir($path)) {
                throw new CredentialProjectionException('The provider cleanup failed.');
            }
        };
        $remove($directory);
    }
}
