<?php

namespace App\AI6\Checks;

use RuntimeException;

/**
 * The same execution — run, phase, profile and checked tree — was delivered twice.
 *
 * It is raised before a process starts, so a redelivered step never produces a
 * second checker process and never a second result row.
 */
final class DuplicateCheckExecution extends RuntimeException
{
    public function __construct(public readonly string $resultKey)
    {
        parent::__construct('This check execution already has a bound result.');
    }
}
