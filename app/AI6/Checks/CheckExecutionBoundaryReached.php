<?php

namespace App\AI6\Checks;

use RuntimeException;
use Throwable;

final class CheckExecutionBoundaryReached extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('The checker execution reached a terminal boundary.', 0, $previous);
    }
}
