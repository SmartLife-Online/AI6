<?php

namespace App\AI6\Git;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class IsolatedTreeExporter implements IsolatedTreeExport
{
    public function export(string $source, string $destination, bool $writable = false): void
    {
        $source = $this->regularDirectory($source);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('The isolated export destination already exists.');
        }
        $parent = $this->regularDirectory(dirname($destination));
        $staging = $parent.DIRECTORY_SEPARATOR.'.ai6-export-'.bin2hex(random_bytes(12));

        try {
            $this->validate($source);
            if (! mkdir($staging, 0700)) {
                throw new RuntimeException('The isolated export staging directory could not be created.');
            }
            $this->copy($source, $staging);
            if (! rename($staging, $destination)) {
                throw new RuntimeException('The isolated export could not be published.');
            }
            if ($writable) {
                $this->makeWritable($destination);
            } else {
                $this->makeReadOnly($destination);
            }
        } catch (\Throwable $exception) {
            if (is_dir($staging) && ! is_link($staging)) {
                $this->remove($staging);
            }
            throw $exception;
        }
    }

    private function validate(string $source): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            if ($this->excludedGitMetadata($relative)) {
                continue;
            }
            if (is_link($entry->getPathname()) || (! $entry->isFile() && ! $entry->isDir())) {
                throw new RuntimeException('The isolated export rejects symbolic links and special files.');
            }
            if (str_contains($relative, "\0") || ! str_starts_with($entry->getPathname(), $source.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('The isolated export path is unsafe.');
            }
        }
    }

    private function copy(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($source) + 1);
            if ($this->excludedGitMetadata($relative)) {
                continue;
            }
            $target = $destination.DIRECTORY_SEPARATOR.$relative;
            if ($entry->isDir()) {
                if (! mkdir($target, 0700)) {
                    throw new RuntimeException('The isolated export directory could not be created.');
                }
            } elseif (! copy($entry->getPathname(), $target)) {
                throw new RuntimeException('The isolated export file could not be copied.');
            }
        }
    }

    private function makeReadOnly(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), $entry->isDir() ? 0555 : 0444);
        }
        @chmod($path, 0555);
    }

    private function makeWritable(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), $entry->isDir() ? 0700 : 0600);
        }
        @chmod($path, 0700);
    }

    private function remove(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }

    private function regularDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || str_replace('\\', '/', $real) !== str_replace('\\', '/', $path) || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('The isolated export directory is unavailable or unsafe.');
        }

        return $real;
    }

    private function excludedGitMetadata(string $relative): bool
    {
        return in_array('.git', preg_split('/[\\\\\/]/', $relative) ?: [], true);
    }
}
