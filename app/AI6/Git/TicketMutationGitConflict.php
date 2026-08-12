<?php

namespace App\AI6\Git;

use RuntimeException;

final class TicketMutationGitConflict extends RuntimeException
{
    public function __construct(public readonly string $conflict, string $message)
    {
        parent::__construct($message);
    }
}
