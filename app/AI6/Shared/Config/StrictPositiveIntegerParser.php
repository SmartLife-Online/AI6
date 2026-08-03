<?php

namespace App\AI6\Shared\Config;

final class StrictPositiveIntegerParser
{
    public function parse(string $key, mixed $value, int $maximum): int|ConfigurationViolation
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1) {
            $parsed = (int) $value;
        } else {
            return $this->violation($key, $maximum);
        }

        if ($parsed < 1 || $parsed > $maximum) {
            return $this->violation($key, $maximum);
        }

        return $parsed;
    }

    private function violation(string $key, int $maximum): ConfigurationViolation
    {
        return new ConfigurationViolation(sprintf(
            'Configuration key %s must be a positive integer between 1 and %d.',
            $key,
            $maximum,
        ));
    }
}
