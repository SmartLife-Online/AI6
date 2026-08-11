<?php

namespace App\AI6\Tickets;

final class TicketSourceBlockers
{
    public const INVALID = 'invalid';

    public const VALIDATION_PROFILE_MISMATCH = 'validation_profile_mismatch';

    public const CONTENT_REDACTED = 'content_redacted';

    private const ORDER = [
        self::INVALID,
        self::VALIDATION_PROFILE_MISMATCH,
        self::CONTENT_REDACTED,
    ];

    /** @param list<string> $blockers
     * @return list<string>
     */
    public static function canonicalize(array $blockers): array
    {
        $unique = array_values(array_unique($blockers));
        usort($unique, static function (string $left, string $right): int {
            $leftOrder = array_search($left, self::ORDER, true);
            $rightOrder = array_search($right, self::ORDER, true);

            return ($leftOrder === false ? PHP_INT_MAX : $leftOrder)
                <=> ($rightOrder === false ? PHP_INT_MAX : $rightOrder)
                ?: $left <=> $right;
        });

        return $unique;
    }
}
