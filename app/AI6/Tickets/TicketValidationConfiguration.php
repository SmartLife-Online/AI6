<?php

namespace App\AI6\Tickets;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class TicketValidationConfiguration
{
    public const DEFAULT_MAX_CANDIDATES = 100;

    public const MAX_MAX_CANDIDATES = 1000;

    public function __construct(
        public TicketValidationProfile $profile,
        public int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
    ) {}

    public static function fromConfiguredValues(
        StrictEnumParser $enumParser,
        ?StrictPositiveIntegerParser $integerParser = null,
    ): self {
        $profile = $enumParser->parse(
            'ai6.tickets.validation_profile',
            config('ai6.tickets.validation_profile'),
            array_column(TicketValidationProfile::cases(), 'value'),
        );
        if (! is_string($profile)) {
            throw new ConfigurationException($profile->message);
        }
        $maxCandidates = ($integerParser ?? new StrictPositiveIntegerParser)->parse(
            'ai6.tickets.max_candidates',
            config('ai6.tickets.max_candidates'),
            self::MAX_MAX_CANDIDATES,
        );
        if (! is_int($maxCandidates)) {
            throw new ConfigurationException($maxCandidates->message);
        }

        return new self(TicketValidationProfile::from($profile), $maxCandidates);
    }
}
