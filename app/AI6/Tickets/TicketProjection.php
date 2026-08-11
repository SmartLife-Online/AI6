<?php

namespace App\AI6\Tickets;

use App\AI6\Projects\TicketDocumentState;

final readonly class TicketProjection
{
    /** @param list<TicketValidationError> $errors
     * @param  list<string>  $sourceBlockers
     */
    public function __construct(
        public TicketDocumentState $state,
        public TicketValidationProfile $profile,
        public ?string $contractHash,
        public array $errors,
        public array $sourceBlockers,
        public ?TicketDocument $document = null,
    ) {}

    /** @param list<TicketValidationError> $errors */
    public function withErrors(array $errors): self
    {
        if ($errors === []) {
            return $this;
        }

        return new self(
            TicketDocumentState::INVALID,
            $this->profile,
            null,
            [...$this->errors, ...$errors],
            TicketSourceBlockers::canonicalize([...$this->sourceBlockers, TicketSourceBlockers::INVALID]),
            $this->document,
        );
    }
}
