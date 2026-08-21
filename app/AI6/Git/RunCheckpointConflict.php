<?php

namespace App\AI6\Git;

use RuntimeException;

final class RunCheckpointConflict extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The run checkpoint does not bind every imported path.');
    }
}
