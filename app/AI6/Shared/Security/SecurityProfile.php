<?php

namespace App\AI6\Shared\Security;

enum SecurityProfile: string
{
    case STRICT = 'strict';
    case DEVELOPMENT = 'development';
    case CUSTOM = 'custom';

    /** @return non-empty-list<string> */
    public static function literals(): array
    {
        return array_map(
            static fn (self $profile): string => $profile->value,
            self::cases(),
        );
    }
}
