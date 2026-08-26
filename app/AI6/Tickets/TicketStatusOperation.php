<?php

namespace App\AI6\Tickets;

enum TicketStatusOperation: string
{
    case APPROVE = 'approve';
    case BLOCK = 'block';
    case CANCEL = 'cancel';
    case RETURN_TO_TODO = 'return_to_todo';
    case COMPLETE_REVIEW = 'complete_review';
    case COMPLETE_REPORT_ONLY = 'complete_report_only';

    public function targetFor(string $source): ?string
    {
        return match ($this) {
            self::APPROVE => $source === 'todo' ? 'ready' : null,
            self::BLOCK => in_array($source, ['todo', 'ready', 'in_progress'], true) ? 'blocked' : null,
            self::CANCEL => in_array($source, ['todo', 'ready', 'blocked', 'review', 'in_progress'], true) ? 'cancelled' : null,
            self::RETURN_TO_TODO => in_array($source, ['ready', 'blocked', 'review', 'in_progress'], true) ? 'todo' : null,
            self::COMPLETE_REVIEW => $source === 'review' ? 'done' : null,
            self::COMPLETE_REPORT_ONLY => $source === 'in_progress' ? 'ready' : null,
        };
    }
}
