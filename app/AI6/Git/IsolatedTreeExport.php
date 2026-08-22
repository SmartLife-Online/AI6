<?php

namespace App\AI6\Git;

/** Technical boundary for exporting one Git-metadata-free project tree. */
interface IsolatedTreeExport
{
    public function export(string $source, string $destination, bool $writable = false): void;
}
