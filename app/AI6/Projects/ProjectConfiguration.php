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

    /** @return array{auto_allow: list<string>, require_approval: list<string>} */
    public function scope(): array
    {
        return $this->values['scope'];
    }

    /**
     * The trusted project default for a path outside `auto_allow` and outside
     * every sensible category (plan §8.2): `auto_allow` or `require_approval`.
     *
     * A snapshot written before this key existed, and any other unexpected
     * value, resolves to the server default `auto_allow`; the parser is the
     * only place that accepts a project-provided value at all.
     */
    public function unlistedPaths(): string
    {
        return ($this->values['scope']['unlisted_paths'] ?? null) === 'require_approval'
            ? 'require_approval'
            : 'auto_allow';
    }

    /** @return array<string, int> */
    public function limits(): array
    {
        return $this->values['limits'];
    }
}
