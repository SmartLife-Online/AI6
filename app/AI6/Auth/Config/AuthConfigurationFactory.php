<?php

namespace App\AI6\Auth\Config;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final class AuthConfigurationFactory
{
    public function __construct(
        private readonly StrictPositiveIntegerParser $integerParser = new StrictPositiveIntegerParser,
    ) {}

    public function fromConfiguredValues(): AuthConfiguration
    {
        $configuration = config('ai6.auth');

        if (! is_array($configuration)) {
            throw new ConfigurationException('Configuration key ai6.auth must be an array.');
        }

        $result = $this->inspect($configuration);

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): AuthConfiguration|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.auth must be an array.');
        }

        $loginMaxAttempts = $this->integerParser->parse(
            'AI6_AUTH_LOGIN_MAX_ATTEMPTS',
            $configuration['login_max_attempts'] ?? null,
            100,
        );

        if ($loginMaxAttempts instanceof ConfigurationViolation) {
            return $loginMaxAttempts;
        }

        $loginDecaySeconds = $this->integerParser->parse(
            'AI6_AUTH_LOGIN_DECAY_SECONDS',
            $configuration['login_decay_seconds'] ?? null,
            86400,
        );

        if ($loginDecaySeconds instanceof ConfigurationViolation) {
            return $loginDecaySeconds;
        }

        $sessionLifetimeMinutes = $this->integerParser->parse(
            'AI6_AUTH_SESSION_LIFETIME_MINUTES',
            $configuration['session_lifetime_minutes'] ?? null,
            525600,
        );

        if ($sessionLifetimeMinutes instanceof ConfigurationViolation) {
            return $sessionLifetimeMinutes;
        }

        return new AuthConfiguration(
            $loginMaxAttempts,
            $loginDecaySeconds,
            $sessionLifetimeMinutes,
        );
    }
}
