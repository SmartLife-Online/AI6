<?php

namespace App\AI6\Shared\Process;

final readonly class NativeProcessRuntimeProbe implements ProcessRuntimeProbe
{
    public function checkerRuntimePromises(): array
    {
        $input = config('ai6.execution_mailboxes.checker_root');
        $output = config('ai6.execution_mailboxes.checker_output_root');
        $workspace = config('ai6.checks.runtime.workspace_root');
        $unshare = config('ai6.checks.runtime.unshare_binary');
        $wrapper = config('ai6.checks.runtime.namespace_wrapper');

        $directories = is_string($input) && is_string($output) && is_string($workspace)
            && is_dir($input) && is_dir($output) && is_dir($workspace)
            && ! is_link($input) && ! is_link($output) && ! is_link($workspace);
        $distinct = $directories && count(array_unique([
            realpath($input), realpath($output), realpath($workspace),
        ])) === 3;

        return [
            'input_read_only' => $directories && $this->promise(fn (): bool => $this->mountHas($input, true)),
            'output_separate' => $distinct,
            'workspace_private' => $distinct && $this->promise(fn (): bool => $this->mountHas($workspace, false)),
            'container_read_only' => $this->promise(fn (): bool => in_array('ro', $this->mountOptions('/'), true)),
            'network_isolated' => DIRECTORY_SEPARATOR === '/'
                && is_dir('/sys/class/net')
                && array_values(array_diff(scandir('/sys/class/net') ?: [], ['.', '..', 'lo'])) === [],
            'namespace_tooling' => is_string($unshare) && is_string($wrapper)
                && $this->fixedExecutable($unshare) && $this->fixedExecutable($wrapper)
                && $this->fixedExecutable('/usr/bin/mount') && $this->fixedExecutable('/usr/bin/find')
                && $this->fixedExecutable('/usr/bin/setpriv') && $this->fixedExecutable('/usr/bin/dash'),
        ];
    }

    public function mountOptions(string $path): array
    {
        $lines = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $selected = null;
        $selectedLength = -1;
        foreach (is_array($lines) ? $lines : [] as $line) {
            $parts = explode(' ', $line);
            $separator = array_search('-', $parts, true);
            if ($separator === false || ! isset($parts[4], $parts[5], $parts[$separator + 3])) {
                continue;
            }
            $mount = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $parts[4]);
            if (($path === $mount || str_starts_with($path, rtrim($mount, '/').'/')) && strlen($mount) > $selectedLength) {
                $selected = array_values(array_unique([...explode(',', $parts[5]), ...explode(',', $parts[$separator + 3])]));
                $selectedLength = strlen($mount);
            }
        }
        if ($selected === null) {
            throw new ProcessStartRejectedException('The execution mount could not be verified.');
        }

        return $selected;
    }

    private function mountHas(string $path, bool $readOnly): bool
    {
        $options = $this->mountOptions((string) realpath($path));
        foreach (['nosuid', 'nodev', 'noexec'] as $required) {
            if (! in_array($required, $options, true)) {
                return false;
            }
        }

        return ! $readOnly || in_array('ro', $options, true);
    }

    /** @param callable(): bool $test */
    private function promise(callable $test): bool
    {
        try {
            return $test();
        } catch (\Throwable) {
            return false;
        }
    }

    private function fixedExecutable(string $path): bool
    {
        return str_starts_with($path, '/') && is_file($path) && ! is_link($path)
            && is_executable($path) && ! is_writable($path);
    }
}
