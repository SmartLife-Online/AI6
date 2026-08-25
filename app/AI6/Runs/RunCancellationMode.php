<?php

namespace App\AI6\Runs;

use App\AI6\Tickets\TicketStatusOperation;

enum RunCancellationMode: string
{
    case SOFT = 'soft_cancel';
    case BLOCK = 'block';
    case HARD = 'hard_cancel';

    public function statusOperation(): TicketStatusOperation
    {
        return match ($this) {
            self::SOFT => TicketStatusOperation::RETURN_TO_TODO,
            self::BLOCK => TicketStatusOperation::BLOCK,
            self::HARD => TicketStatusOperation::CANCEL,
        };
    }

    public function requiresApprover(): bool
    {
        return $this !== self::SOFT;
    }
}
