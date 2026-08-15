<?php

namespace App\AI6\Tickets;

enum TicketStatusOperation: string
{
    case APPROVE = 'approve';
    case BLOCK = 'block';
    case CANCEL = 'cancel';
    case RETURN_TO_TODO = 'return_to_todo';
    case COMPLETE_REVIEW = 'complete_review';

    public function targetFor(string $source): ?string
    {
        return match ($this) {
            self::APPROVE => $source === 'todo' ? 'ready' : null,
            self::BLOCK => in_array($source, ['todo', 'ready'], true) ? 'blocked' : null,
            self::CANCEL => in_array($source, ['todo', 'ready', 'blocked', 'review'], true) ? 'cancelled' : null,
            self::RETURN_TO_TODO => in_array($source, ['ready', 'blocked', 'review'], true) ? 'todo' : null,
            self::COMPLETE_REVIEW => $source === 'review' ? 'done' : null,
        };
    }
}
