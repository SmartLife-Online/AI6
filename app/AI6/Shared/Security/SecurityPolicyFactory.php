<?php

namespace App\AI6\Shared\Security;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictBooleanParser;
use App\AI6\Shared\Config\StrictEnumParser;

final class SecurityPolicyFactory
{
    public function __construct(
        private readonly StrictBooleanParser $booleanParser = new StrictBooleanParser,
        private readonly StrictEnumParser $enumParser = new StrictEnumParser,
    ) {}

    public function fromConfiguredValues(): SecurityPolicy
    {
        $configuration = config('ai6.security');

        if (! is_array($configuration)) {
            throw new ConfigurationException('Configuration key ai6.security must be an array.');
        }

        $result = $this->inspect($configuration);

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): SecurityPolicy|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.security must be an array.');
        }

        $profileLiteral = $this->enumParser->parse(
            'AI6_SECURITY_PROFILE',
            $configuration['profile'] ?? null,
            SecurityProfile::literals(),
        );

        if ($profileLiteral instanceof ConfigurationViolation) {
            return $profileLiteral;
        }

        $profile = SecurityProfile::from($profileLiteral);
        $acknowledged = $this->booleanParser->parse(
            'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE',
            $configuration['acknowledge_reduced_mode'] ?? null,
        );

        if ($acknowledged instanceof ConfigurationViolation) {
            return $acknowledged;
        }

        $configuredMeasures = $configuration['measures'] ?? null;

        if (! is_array($configuredMeasures)) {
            return new ConfigurationViolation('Configuration key ai6.security.measures must be an array.');
        }

        $parsedMeasures = [];
        foreach (SecurityMeasure::cases() as $measure) {
            $parsed = $this->booleanParser->parse(
                $measure->value,
                $configuredMeasures[$measure->value] ?? null,
            );

            if ($parsed instanceof ConfigurationViolation) {
                return $parsed;
            }

            $parsedMeasures[$measure->value] = $parsed;
        }

        $effectiveMeasures = match ($profile) {
            SecurityProfile::STRICT => $this->strictMeasures($parsedMeasures),
            SecurityProfile::DEVELOPMENT => $this->developmentMeasures($parsedMeasures),
            SecurityProfile::CUSTOM => $parsedMeasures,
        };

        if ($effectiveMeasures instanceof ConfigurationViolation) {
            return $effectiveMeasures;
        }

        $disabled = array_keys(array_filter(
            $effectiveMeasures,
            static fn (bool $enabled): bool => ! $enabled,
        ));

        if ($disabled !== [] && ! $acknowledged) {
            return new ConfigurationViolation(sprintf(
                'Reduced security policy requires AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE=true; disabled measures: %s.',
                implode(', ', $disabled),
            ));
        }

        return new SecurityPolicy($profile, $effectiveMeasures, $acknowledged);
    }

    /**
     * @param  array<string, bool>  $configured
     * @return array<string, bool>|ConfigurationViolation
     */
    private function strictMeasures(array $configured): array|ConfigurationViolation
    {
        $disabled = array_keys(array_filter(
            $configured,
            static fn (bool $enabled): bool => ! $enabled,
        ));

        if ($disabled !== []) {
            return new ConfigurationViolation(sprintf(
                'Security profile strict does not permit disabled measures: %s.',
                implode(', ', $disabled),
            ));
        }

        return $configured;
    }

    /**
     * @param  array<string, bool>  $configured
     * @return array<string, bool>|ConfigurationViolation
     */
    private function developmentMeasures(array $configured): array|ConfigurationViolation
    {
        $httpsMeasure = SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value;
        $unexpectedDisabled = array_keys(array_filter(
            $configured,
            static fn (bool $enabled, string $measure): bool => ! $enabled && $measure !== $httpsMeasure,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($unexpectedDisabled !== []) {
            return new ConfigurationViolation(sprintf(
                'Security profile development does not permit additional disabled measures: %s.',
                implode(', ', $unexpectedDisabled),
            ));
        }

        $effective = array_fill_keys(array_keys($configured), true);
        $effective[$httpsMeasure] = false;

        return $effective;
    }
}
