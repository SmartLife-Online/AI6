<?php

namespace App\AI6\HumanLoop;

use RuntimeException;

final class HumanRequestRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
