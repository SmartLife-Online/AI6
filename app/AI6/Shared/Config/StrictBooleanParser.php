<?php

namespace App\AI6\Shared\Config;

final class StrictBooleanParser
{
    private const LITERALS = [
        'true' => true,
        'false' => false,
        '1' => true,
        '0' => false,
        'yes' => true,
        'no' => false,
        'on' => true,
        'off' => false,
    ];

    public function parse(string $key, mixed $value): bool|ConfigurationViolation
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $literal = strtolower($value);

            if (array_key_exists($literal, self::LITERALS)) {
                return self::LITERALS[$literal];
            }
        }

        return new ConfigurationViolation(sprintf(
            'Configuration key %s must use one of: true, false, 1, 0, yes, no, on, off.',
            $key,
        ));
    }
}
