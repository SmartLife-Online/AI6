<?php

namespace App\AI6\Runs;

use RuntimeException;

/** The freigegebene max_added_scope_paths would be exceeded by this approval (AC-04). */
final class ScopePathLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $observed, public readonly int $maximum)
    {
        parent::__construct('The additional scope path would exceed the approved max_added_scope_paths limit.');
    }
}
