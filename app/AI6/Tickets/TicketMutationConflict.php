<?php

namespace App\AI6\Tickets;

use RuntimeException;

final class TicketMutationConflict extends RuntimeException
{
    public function __construct(public readonly string $conflict, string $message)
    {
        parent::__construct($message);
    }
}
