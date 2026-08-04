<?php

namespace App\AI6\Auth\Config;

final readonly class AuthConfiguration
{
    public function __construct(
        public int $loginMaxAttempts,
        public int $loginDecaySeconds,
        public int $sessionLifetimeMinutes,
        public int $loginConfirmationTtlSeconds,
        public int $loginConfirmationMaxAttempts,
        public int $strongAuthenticationMaxAttempts,
        public int $strongAuthenticationDecaySeconds,
        public int $loginConfirmationResendCooldownSeconds,
        public int $stepUpWindowSeconds,
        public int $enrollmentTtlSeconds,
        public ?string $loginConfirmationEmail,
    ) {}
}
