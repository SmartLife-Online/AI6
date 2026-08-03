<?php

namespace App\AI6\Auth\Config;

final readonly class AuthConfiguration
{
    public function __construct(
        public int $loginMaxAttempts,
        public int $loginDecaySeconds,
        public int $sessionLifetimeMinutes,
    ) {}
}
