<?php

namespace App\AI6\Tickets;

use RuntimeException;

final class TicketParseException extends RuntimeException
{
    /** @param non-empty-list<TicketValidationError> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Ticket input could not be parsed.');
    }
}
