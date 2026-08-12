<?php

namespace App\AI6\Tickets;

final class TicketContentStatus
{
    public function replace(string $content, string $expectedSource, string $target): string
    {
        $closing = strpos($content, "\n---\n", 4);
        if (! str_starts_with($content, "---\n") || $closing === false) {
            throw new TicketMutationConflict('ticket_frontmatter_unavailable', 'Das Ticket-Frontmatter ist nicht eindeutig ersetzbar.');
        }

        $frontmatter = substr($content, 0, $closing + 1);
        $count = preg_match_all('/^status: ([a-z_]+)$/m', $frontmatter, $matches);
        if ($count !== 1 || $matches[1][0] !== $expectedSource) {
            throw new TicketMutationConflict('ticket_status_binding_changed', 'Der gebundene Quellstatus stimmt nicht mit dem Ticket überein.');
        }

        $replaced = preg_replace('/^status: '.preg_quote($expectedSource, '/').'$/m', 'status: '.$target, $frontmatter, 1, $replacements);
        if (! is_string($replaced) || $replacements !== 1) {
            throw new TicketMutationConflict('ticket_status_replacement_failed', 'Der Ticketstatus konnte nicht eindeutig ersetzt werden.');
        }

        return $replaced.substr($content, $closing + 1);
    }
}
