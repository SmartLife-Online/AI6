<?php

namespace App\AI6\Auth;

use InvalidArgumentException;

final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new InvalidArgumentException('Invalid base64url input.');
        }

        $decoded = base64_decode(
            strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true,
        );

        if (! is_string($decoded) || ! hash_equals(self::encode($decoded), $encoded)) {
            throw new InvalidArgumentException('Invalid base64url input.');
        }

        return $decoded;
    }
}
