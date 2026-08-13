<?php

namespace App\AI6\Tickets;

enum TicketStatus: string
{
    case TODO = 'todo';
    case READY = 'ready';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED = 'blocked';
    case REVIEW = 'review';
    case DONE = 'done';
    case CANCELLED = 'cancelled';
}
