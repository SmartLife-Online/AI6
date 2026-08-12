<?php

namespace App\AI6\Git;

/**
 * The closed vocabulary of failure texts that may appear verbatim in HTTP
 * views. Everything else persisted in last_error is an internal (already
 * redacted) diagnostic and must never become response content; views render
 * a generic failure state for it instead.
 */
final class ControlOperationPublicFailureText
{
    public const LEASE_LOST = 'Die Operation hat ihre Projektsperre verloren und wird erneut versucht.';

    public const FENCING_CONFLICT = 'Der Publish wurde durch einen neueren Operationsversuch verworfen.';

    public const EFFECT_LOCK_UNAVAILABLE = 'Der Effekt-Lock ist für diese Operation derzeit nicht sicher verfügbar; sie wird erneut versucht.';

    public static function displayable(?string $lastError): ?string
    {
        return in_array($lastError, [
            self::LEASE_LOST,
            self::FENCING_CONFLICT,
            self::EFFECT_LOCK_UNAVAILABLE,
        ], true) ? $lastError : null;
    }
}
