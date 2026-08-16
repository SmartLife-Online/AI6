<?php

namespace App\AI6\Runs;

use RuntimeException;

final class RunTransitionConflict extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
