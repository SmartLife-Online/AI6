<?php

namespace App\AI6\Tickets;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\TicketReadModel;

final readonly class TicketViewModel
{
    /**
     * @param  list<string>  $dependsOn
     * @param  list<string>  $staleReasons
     */
    public function __construct(
        public TicketReadModel $readModel,
        public ?TicketDocument $document,
        public ?string $ticketId,
        public ?string $title,
        public ?string $ticketStatus,
        public array $dependsOn,
        public ?string $goal,
        public bool $isStale,
        public array $staleReasons,
        public bool $allowsEditor,
        public bool $allowsApproval,
        public ?ControlOperation $latestRefresh,
    ) {}
}
