<?php

namespace Tests\Unit\Shared\Security;

use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Security\SecurityControlGroup;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use App\AI6\Shared\Security\SecurityProfile;
use PHPUnit\Framework\TestCase;

final class SecurityPolicyFactoryTest extends TestCase
{
    public function test_strict_defaults_enable_every_canonical_measure(): void
    {
        $policy = $this->policy($this->configuration());

        self::assertSame(SecurityProfile::STRICT, $policy->profile);
        self::assertFalse($policy->reducedModeAcknowledged);
        self::assertSame([], $policy->disabledMeasures());
        self::assertCount(7, $policy->measures());

        foreach (SecurityMeasure::cases() as $measure) {
            self::assertTrue($policy->isEnabled($measure));
        }
    }

    public function test_development_has_exactly_the_normative_reduction_and_requires_acknowledgement(): void
    {
        $configuration = $this->configuration('development', true);
        $policy = $this->policy($configuration);

        self::assertSame(
            [SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS],
            $policy->disabledMeasures(),
        );

        foreach (SecurityMeasure::cases() as $measure) {
            self::assertSame(
                $measure !== SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS,
                $policy->isEnabled($measure),
            );
        }

        $configuration['acknowledge_reduced_mode'] = false;
        $this->violation($configuration);
    }

    public function test_development_rejects_an_additional_disabled_measure(): void
    {
        $configuration = $this->configuration('development', true);
        $configuration['measures'][SecurityMeasure::REQUIRE_AGENT_SANDBOX->value] = false;

        $violation = $this->violation($configuration);
        self::assertStringContainsString(SecurityMeasure::REQUIRE_AGENT_SANDBOX->value, $violation->message);
    }

    public function test_custom_reduction_names_every_disabled_measure_and_starts_only_when_acknowledged(): void
    {
        $configuration = $this->configuration('custom');
        $disabled = [
            SecurityMeasure::LOGIN_EMAIL_CONFIRMATION,
            SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW,
        ];

        foreach ($disabled as $measure) {
            $configuration['measures'][$measure->value] = false;
        }

        $violation = $this->violation($configuration);
        foreach ($disabled as $measure) {
            self::assertStringContainsString($measure->value, $violation->message);
        }

        $configuration['acknowledge_reduced_mode'] = 'YES';
        $policy = $this->policy($configuration);
        self::assertSame($disabled, $policy->disabledMeasures());
        self::assertTrue($policy->reducedModeAcknowledged);
        self::assertSame([
            'profile' => 'custom',
            'disabled_measures' => array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                $disabled,
            ),
            'reduced_mode_acknowledged' => true,
        ], $policy->bannerData()->toArray());
    }

    public function test_strict_rejects_measure_reduction_instead_of_silently_ignoring_it(): void
    {
        $configuration = $this->configuration('strict', true);
        $configuration['measures'][SecurityMeasure::REQUIRE_PRIVILEGED_PASSKEY->value] = false;

        $this->violation($configuration);
    }

    public function test_every_profile_measure_and_acknowledgement_combination_has_the_normative_result(): void
    {
        $measures = SecurityMeasure::cases();

        foreach (SecurityProfile::cases() as $profile) {
            for ($mask = 0; $mask < (1 << count($measures)); $mask++) {
                foreach ([false, true] as $acknowledged) {
                    $configuration = $this->configuration($profile->value, $acknowledged);
                    $configuredStates = [];

                    foreach ($measures as $index => $measure) {
                        $enabled = ($mask & (1 << $index)) !== 0;
                        $configuration['measures'][$measure->value] = $enabled;
                        $configuredStates[$measure->value] = $enabled;
                    }

                    $allEnabled = ! in_array(false, $configuredStates, true);
                    $allNonHttpsEnabled = ! in_array(false, array_filter(
                        $configuredStates,
                        static fn (string $key): bool => $key !== SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value,
                        ARRAY_FILTER_USE_KEY,
                    ), true);
                    $expectedValid = match ($profile) {
                        SecurityProfile::STRICT => $allEnabled,
                        SecurityProfile::DEVELOPMENT => $acknowledged && $allNonHttpsEnabled,
                        SecurityProfile::CUSTOM => $allEnabled || $acknowledged,
                    };
                    $result = (new SecurityPolicyFactory)->inspect($configuration);

                    if (! $expectedValid) {
                        self::assertInstanceOf(ConfigurationViolation::class, $result);

                        continue;
                    }

                    self::assertInstanceOf(SecurityPolicy::class, $result);
                    $expectedStates = match ($profile) {
                        SecurityProfile::STRICT => $configuredStates,
                        SecurityProfile::DEVELOPMENT => array_replace($configuredStates, [
                            SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value => false,
                        ]),
                        SecurityProfile::CUSTOM => $configuredStates,
                    };
                    self::assertSame($expectedStates, $result->measures());
                }
            }
        }
    }

    public function test_measure_enum_is_total_injective_and_maps_to_exactly_five_control_groups(): void
    {
        $expectedKeys = [
            'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION',
            'AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY',
            'AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP',
            'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS',
            'AI6_SECURITY_REQUIRE_AGENT_SANDBOX',
            'AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION',
            'AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW',
        ];
        $actualKeys = array_map(
            static fn (SecurityMeasure $measure): string => $measure->value,
            SecurityMeasure::cases(),
        );

        self::assertSame($expectedKeys, $actualKeys);
        self::assertCount(7, array_unique($actualKeys));
        self::assertCount(5, array_unique(array_map(
            static fn (SecurityMeasure $measure): string => $measure->controlGroup()->value,
            SecurityMeasure::cases(),
        )));
        self::assertCount(5, SecurityControlGroup::cases());

        $configSource = $this->read('config/ai6.php');
        preg_match_all('/AI6_SECURITY_[A-Z_]+/', $configSource, $matches);
        self::assertSame([
            'AI6_SECURITY_PROFILE',
            'AI6_SECURITY_ACKNOWLEDGE_REDUCED_MODE',
            ...$expectedKeys,
        ], array_values(array_unique($matches[0])));

        foreach (['CSRF', 'PATH_VALIDATION', 'REF_VALIDATION', 'JSON_VALIDATION', 'SHELL', 'CREDENTIAL_SEPARATION', 'ANTI_REPLAY'] as $forbidden) {
            self::assertStringNotContainsString('AI6_SECURITY_'.$forbidden, $configSource);
        }
    }

    /** @return array<string, mixed> */
    private function configuration(string $profile = 'strict', bool|string $acknowledged = false): array
    {
        return [
            'profile' => $profile,
            'acknowledge_reduced_mode' => $acknowledged,
            'measures' => array_fill_keys(array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                SecurityMeasure::cases(),
            ), 'true'),
        ];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4).'/'.$path);
        self::assertNotFalse($contents);

        return $contents;
    }

    /** @param array<string, mixed> $configuration */
    private function policy(array $configuration): SecurityPolicy
    {
        $result = (new SecurityPolicyFactory)->inspect($configuration);
        self::assertInstanceOf(SecurityPolicy::class, $result);

        return $result;
    }

    /** @param array<string, mixed> $configuration */
    private function violation(array $configuration): ConfigurationViolation
    {
        $result = (new SecurityPolicyFactory)->inspect($configuration);
        self::assertInstanceOf(ConfigurationViolation::class, $result);

        return $result;
    }
}
