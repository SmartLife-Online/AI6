<?php

namespace App\AI6\Shared\Config;

final class StrictEnumParser
{
    /**
     * @param  non-empty-list<string>  $allowed
     */
    public function parse(string $key, mixed $value, array $allowed): string|ConfigurationViolation
    {
        if (is_string($value)) {
            $normalized = strtolower($value);

            foreach ($allowed as $literal) {
                if ($normalized === strtolower($literal)) {
                    return $literal;
                }
            }
        }

        return new ConfigurationViolation(sprintf(
            'Configuration key %s must use one of: %s.',
            $key,
            implode(', ', $allowed),
        ));
    }
}
