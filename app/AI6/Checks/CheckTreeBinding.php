<?php

namespace App\AI6\Checks;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * A deterministic binding of the tree a check ran against.
 *
 * Mutation detection needs a value from before the run as well as after it: a
 * comparison that only looks at the tree afterwards cannot be falsified. The
 * binding covers every relative path, whether the entry is a file or a
 * directory, whether it is executable, and the content digest.
 */
final readonly class CheckTreeBinding
{
    public function hash(string $tree): string
    {
        $real = realpath($tree);
        if ($real === false || is_link($tree) || ! is_dir($real)) {
            throw new RuntimeException('The checked tree is unavailable.');
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($real) + 1));
            if ($entry->isLink()) {
                $entries[$relative] = 'l:'.hash('sha256', (string) readlink($entry->getPathname()));

                continue;
            }
            if ($entry->isDir()) {
                $entries[$relative] = 'd:';

                continue;
            }
            $digest = hash_file('sha256', $entry->getPathname());
            if ($digest === false) {
                throw new RuntimeException('A checked tree entry could not be digested.');
            }
            $entries[$relative] = 'f:'.($entry->isExecutable() ? '1' : '0').':'.$digest;
        }

        ksort($entries);
        $canonical = '';
        foreach ($entries as $relative => $value) {
            $canonical .= $relative."\0".$value."\n";
        }

        return hash('sha256', $canonical);
    }
}
