<?php

namespace App\AI6\Shared\Process;

use App\AI6\Agents\ProviderOnboarding;

/** A supervisor-owned filesystem view for one provider invocation (AGT-007). */
final readonly class AgentProcessScope
{
    public const SANDBOX_WORK_DIRECTORY = '/tmp/ai6-provider-sandbox';

    /**
     * @param  list<string>  $readOnlyDirectories
     * @param  list<string>  $writableDirectories
     * @param  list<string>  $readOnlyFiles
     * @param  null|\Closure(): void  $heartbeat
     */
    public function __construct(
        public array $readOnlyDirectories,
        public array $writableDirectories,
        public ?\Closure $heartbeat = null,
        public array $readOnlyFiles = [],
    ) {}

    /** @return non-empty-list<string> */
    public function command(ProcessRequest $request): array
    {
        $binary = config('ai6.provider_onboarding.bubblewrap_binary');
        if (DIRECTORY_SEPARATOR !== '/' || config('ai6.runtime_role') !== ExecutionRole::AGENT->value
            || ! is_string($binary) || ! is_executable($binary) || is_link($binary)) {
            throw new ProcessStartRejectedException('The provider namespace boundary is unavailable.');
        }

        // Start with an empty root. No bind of /, /var, /run, /tmp, or the
        // application tree can expose supervisor state, another turn or a store.
        $command = [$binary, '--die-with-parent', '--unshare-user', '--unshare-pid', '--unshare-ipc',
            '--unshare-uts', '--cap-drop', 'ALL', '--new-session'];
        foreach (['store_root', 'report_root', 'presence_root', 'private_root'] as $key) {
            $protected = ProviderOnboarding::path($key);
            foreach (['/usr', '/bin', '/sbin', '/lib', '/lib64', '/etc'] as $system) {
                if ($protected === $system || str_starts_with($protected, $system.'/') || str_starts_with($system, $protected.'/')) {
                    throw new ProcessStartRejectedException('The provider state overlaps the system projection.');
                }
            }
        }
        foreach (['/usr', '/bin', '/sbin', '/lib', '/lib64'] as $path) {
            if (is_link($path)) {
                $target = readlink($path);
                if (! is_string($target)) {
                    throw new ProcessStartRejectedException('The provider system path is unavailable.');
                }
                array_push($command, '--symlink', $target, $path);
            } elseif (is_dir($path)) {
                array_push($command, '--ro-bind', $path, $path);
            }
        }
        foreach (['/etc/ssl', '/etc/ld.so.cache', '/etc/resolv.conf', '/etc/hosts', '/etc/nsswitch.conf', '/etc/passwd', '/etc/group'] as $path) {
            if (file_exists($path)) {
                array_push($command, '--ro-bind', $path, $path);
            }
        }
        array_push($command, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp');
        // A fresh directory in the invocation's private tmpfs, never a host bind.
        // It disappears with the namespace, including mode-000 bwrap placeholders.
        array_push($command, '--perms', '0700', '--dir', self::SANDBOX_WORK_DIRECTORY);
        $executable = $request->command[0];
        if (! is_file($executable) || is_link($executable) || ! is_executable($executable) || realpath($executable) !== $executable) {
            throw new ProcessStartRejectedException('The provider executable is not a regular pinned file.');
        }
        if (! str_starts_with($executable, '/usr/')) {
            foreach (['store_root', 'report_root', 'presence_root', 'private_root'] as $key) {
                if (str_starts_with($executable, ProviderOnboarding::path($key).'/')) {
                    throw new ProcessStartRejectedException('The provider executable overlaps supervisor state.');
                }
            }
            array_push($command, '--ro-bind', $executable, $executable);
        }
        foreach ([$this->readOnlyDirectories, $this->writableDirectories] as $index => $paths) {
            foreach ($paths as $path) {
                $this->assertDirectory($path);
                array_push($command, $index === 0 ? '--ro-bind' : '--bind', $path, $path);
            }
        }
        foreach ($this->readOnlyFiles as $path) {
            $this->assertDirectory(dirname($path));
            if (! is_file($path) || is_link($path) || realpath($path) !== $path) {
                throw new ProcessStartRejectedException('The provider read-only file is invalid.');
            }
            array_push($command, '--ro-bind', $path, $path);
        }
        array_push($command, '--chdir', $request->workingDirectory, '--remount-ro', '/', '--', ...$request->command);

        return $command;
    }

    private function assertDirectory(string $path): void
    {
        if (! str_starts_with($path, '/') || ! is_dir($path) || realpath($path) !== $path
            || in_array($path, ['/', '/usr', '/etc', '/var', '/run', '/tmp', '/opt'], true)) {
            throw new ProcessStartRejectedException('The provider filesystem projection is invalid.');
        }
        foreach (['store_root', 'report_root', 'presence_root'] as $key) {
            $protected = config('ai6.provider_onboarding.'.$key);
            if (! is_string($protected) || $protected === '' || $path === $protected
                || str_starts_with($protected, $path.'/') || str_starts_with($path, rtrim($protected, '/').'/')) {
                throw new ProcessStartRejectedException('The provider filesystem projection exposes supervisor state.');
            }
        }
        $private = ProviderOnboarding::path('private_root');
        if ($path === $private || str_starts_with($private, $path.'/')) {
            throw new ProcessStartRejectedException('The provider filesystem projection exposes foreign private state.');
        }
    }
}
