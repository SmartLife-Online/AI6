<?php

namespace App\AI6\Git;

use RuntimeException;

final class ControlOperationTerminalConflict extends RuntimeException
{
    public function __construct(
        public readonly string $conflict,
        string $message,
    ) {
        parent::__construct($message);
    }
}
