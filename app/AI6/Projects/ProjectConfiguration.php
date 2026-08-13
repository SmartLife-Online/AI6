<?php

namespace App\AI6\Projects;

use App\AI6\Tickets\TicketValidationProfile;

final readonly class ProjectConfiguration
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values) {}

    public function ticketsPath(): string
    {
        return $this->values['tickets_path'];
    }

    public function ticketValidationProfile(): TicketValidationProfile
    {
        return TicketValidationProfile::from($this->values['ticket_validation_profile']);
    }
}
