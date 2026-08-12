<?php

namespace App\AI6\Tickets;

final readonly class DependencyBadge
{
    public const SATISFIED = 'satisfied';

    public const OPEN = 'open';

    public const MISSING = 'missing';

    public const UNKNOWN = 'unknown';

    public const UNREADABLE = 'unreadable';

    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        public string $ticketId,
        public string $kind,
        public ?string $targetStatus,
    ) {}

    public function isError(): bool
    {
        return in_array($this->kind, [self::MISSING, self::UNKNOWN, self::UNREADABLE, self::AMBIGUOUS], true);
    }
}
