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

        $loginConfirmationTtlSeconds = $this->integerParser->parse(
            'AI6_AUTH_LOGIN_CONFIRMATION_TTL_SECONDS',
            $configuration['login_confirmation_ttl_seconds'] ?? null,
            86400,
        );

        if ($loginConfirmationTtlSeconds instanceof ConfigurationViolation) {
            return $loginConfirmationTtlSeconds;
        }

        $loginConfirmationMaxAttempts = $this->integerParser->parse(
            'AI6_AUTH_LOGIN_CONFIRMATION_MAX_ATTEMPTS',
            $configuration['login_confirmation_max_attempts'] ?? null,
            20,
        );

        if ($loginConfirmationMaxAttempts instanceof ConfigurationViolation) {
            return $loginConfirmationMaxAttempts;
        }

        $strongAuthenticationMaxAttempts = $this->integerParser->parse(
            'AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS',
            $configuration['strong_authentication_max_attempts'] ?? null,
            20,
        );

        if ($strongAuthenticationMaxAttempts instanceof ConfigurationViolation) {
            return $strongAuthenticationMaxAttempts;
        }

        $strongAuthenticationDecaySeconds = $this->integerParser->parse(
            'AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS',
            $configuration['strong_authentication_decay_seconds'] ?? null,
            86400,
        );

        if ($strongAuthenticationDecaySeconds instanceof ConfigurationViolation) {
            return $strongAuthenticationDecaySeconds;
        }

        $loginConfirmationResendCooldownSeconds = $this->integerParser->parse(
            'AI6_AUTH_LOGIN_CONFIRMATION_RESEND_COOLDOWN_SECONDS',
            $configuration['login_confirmation_resend_cooldown_seconds'] ?? null,
            3600,
        );

        if ($loginConfirmationResendCooldownSeconds instanceof ConfigurationViolation) {
            return $loginConfirmationResendCooldownSeconds;
        }

        $stepUpWindowSeconds = $this->integerParser->parse(
            'AI6_AUTH_STEP_UP_WINDOW_SECONDS',
            $configuration['step_up_window_seconds'] ?? null,
            86400,
        );

        if ($stepUpWindowSeconds instanceof ConfigurationViolation) {
            return $stepUpWindowSeconds;
        }

        $enrollmentTtlSeconds = $this->integerParser->parse(
            'AI6_AUTH_ENROLLMENT_TTL_SECONDS',
            $configuration['enrollment_ttl_seconds'] ?? null,
            86400,
        );

        if ($enrollmentTtlSeconds instanceof ConfigurationViolation) {
            return $enrollmentTtlSeconds;
        }

        $email = $configuration['login_confirmation_email'] ?? null;
        $loginConfirmationEmail = is_string($email) && trim($email) !== ''
            ? trim($email)
            : null;

        return new AuthConfiguration(
            $loginMaxAttempts,
            $loginDecaySeconds,
            $sessionLifetimeMinutes,
            $loginConfirmationTtlSeconds,
            $loginConfirmationMaxAttempts,
            $strongAuthenticationMaxAttempts,
            $strongAuthenticationDecaySeconds,
            $loginConfirmationResendCooldownSeconds,
            $stepUpWindowSeconds,
            $enrollmentTtlSeconds,
            $loginConfirmationEmail,
        );
    }
}
