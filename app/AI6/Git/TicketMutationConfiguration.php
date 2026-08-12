<?php

namespace App\AI6\Git;

final readonly class TicketMutationConfiguration
{
    public function __construct(
        public string $authorName,
        public string $authorEmail,
    ) {}
}
