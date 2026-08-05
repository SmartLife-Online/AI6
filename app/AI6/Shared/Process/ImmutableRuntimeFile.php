<?php

namespace App\AI6\Shared\Process;

final class ImmutableRuntimeFile
{
    public static function violation(string $path, bool $mustBeExecutable): ?string
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        if ($real === false || $real !== $path || is_link($path) || ! is_file($path)) {
            return 'is unavailable, non-canonical, or a symlink';
        }

        if ($mustBeExecutable && ! is_executable($path)) {
            return 'is not executable';
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path);
            if (! is_int($mode) || ($mode & 0022) !== 0) {
                return 'is writable by its group or by other identities';
            }

            if (is_writable($path)) {
                return 'is writable by the runtime identity';
            }
        }

        return null;
    }
}
