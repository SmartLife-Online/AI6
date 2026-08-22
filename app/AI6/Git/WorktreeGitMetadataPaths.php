<?php

namespace App\AI6\Git;

/** Resolve every managed Git metadata path that an isolated agent turn must not reach. */
final readonly class WorktreeGitMetadataPaths
{
    /** @return list<string> */
    public function resolve(string $worktree): array
    {
        $gitEntry = rtrim($worktree, '/\\').'/.git';
        $paths = [$worktree, $gitEntry];
        if (is_dir($gitEntry) && ! is_link($gitEntry)) {
            $paths[] = $gitEntry.DIRECTORY_SEPARATOR.'refs';

            return $paths;
        }
        $gitDirectory = $this->referencedDirectory($gitEntry, 'gitdir:');
        if ($gitDirectory === null) {
            return $paths;
        }

        $paths[] = $gitDirectory;
        $paths[] = $gitDirectory.DIRECTORY_SEPARATOR.'refs';
        $commonEntry = $gitDirectory.DIRECTORY_SEPARATOR.'commondir';
        $paths[] = $commonEntry;
        $commonDirectory = $this->referencedDirectory($commonEntry);
        if ($commonDirectory !== null) {
            $paths[] = $commonDirectory;
            $paths[] = $commonDirectory.DIRECTORY_SEPARATOR.'refs';
        }

        return array_values(array_unique($paths));
    }

    private function referencedDirectory(string $file, ?string $prefix = null): ?string
    {
        if (! is_file($file) || is_link($file)) {
            return null;
        }
        $bytes = file_get_contents($file);
        if (! is_string($bytes)) {
            return null;
        }
        $reference = trim($bytes);
        if ($prefix !== null) {
            if (! str_starts_with(strtolower($reference), $prefix)) {
                return null;
            }
            $reference = trim(substr($reference, strlen($prefix)));
        }
        if ($reference === '' || str_contains($reference, "\0")) {
            return null;
        }
        if (! $this->absolute($reference)) {
            $reference = dirname($file).DIRECTORY_SEPARATOR.$reference;
        }
        $resolved = realpath($reference);

        return is_string($resolved) && is_dir($resolved) && ! is_link($resolved) ? $resolved : null;
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
