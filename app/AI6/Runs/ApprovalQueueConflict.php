<?php

namespace App\AI6\Runs;

use RuntimeException;

final class ApprovalQueueConflict extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The approval queue changed before the requested transition.');
    }
}
