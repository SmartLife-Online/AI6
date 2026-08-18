<?php

namespace App\AI6\HumanLoop;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class HumanRequestNotificationConfiguration
{
    public function __construct(
        public int $maxAttempts,
        public int $retrySeconds,
    ) {}

    public static function fromConfiguredValues(StrictPositiveIntegerParser $parser): self
    {
        $values = config('ai6.human_requests');
        if (! is_array($values) || array_keys($values) !== ['notification_max_attempts', 'notification_retry_seconds']) {
            throw new ConfigurationException('Configuration key ai6.human_requests must contain the canonical notification fields.');
        }

        return new self(
            self::parse($parser, 'ai6.human_requests.notification_max_attempts', $values['notification_max_attempts'], 100),
            self::parse($parser, 'ai6.human_requests.notification_retry_seconds', $values['notification_retry_seconds'], 86400),
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
