<?php

namespace App\AI6\Runs;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class RunStepConfiguration
{
    public function __construct(public int $leaseSeconds, public int $maxAttempts) {}

    public static function fromConfiguredValues(StrictPositiveIntegerParser $parser): self
    {
        $values = config('ai6.run_steps');
        if (! is_array($values) || array_keys($values) !== ['lease_seconds', 'max_attempts']) {
            throw new ConfigurationException('Configuration key ai6.run_steps must contain the canonical step fields.');
        }

        return new self(
            self::parse($parser, 'ai6.run_steps.lease_seconds', $values['lease_seconds'], 86400),
            self::parse($parser, 'ai6.run_steps.max_attempts', $values['max_attempts'], 100),
        );
    }

    private static function parse(StrictPositiveIntegerParser $parser, string $key, mixed $value, int $maximum): int
    {
        $parsed = $parser->parse($key, $value, $maximum);
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }
}
