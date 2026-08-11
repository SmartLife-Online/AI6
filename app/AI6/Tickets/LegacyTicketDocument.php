<?php

namespace App\AI6\Tickets;

final readonly class LegacyTicketDocument
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields, public string $yaml) {}
}
