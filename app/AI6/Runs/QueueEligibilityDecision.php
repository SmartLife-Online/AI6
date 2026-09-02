<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\TicketReadModel;

final readonly class QueueEligibilityDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $eligible,
        public array $reasons,
        public ?TicketReadModel $readModel,
    ) {}

    /** @return array{eligible: bool, reasons: list<string>} */
    public function toArray(): array
    {
        return ['eligible' => $this->eligible, 'reasons' => $this->reasons];
    }
}
