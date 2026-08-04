<?php

namespace Tests\Unit\Auth;

use App\AI6\Auth\Config\AuthConfiguration;
use App\AI6\Auth\Config\AuthConfigurationFactory;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthConfigurationTest extends TestCase
{
    public function test_auth_limits_resolve_to_an_immutable_typed_contract(): void
    {
        $result = (new AuthConfigurationFactory)->inspect([
            'login_max_attempts' => '5',
            'login_decay_seconds' => '60',
            'session_lifetime_minutes' => '120',
            'login_confirmation_ttl_seconds' => '600',
            'login_confirmation_max_attempts' => '5',
            'strong_authentication_max_attempts' => '5',
            'strong_authentication_decay_seconds' => '300',
            'login_confirmation_resend_cooldown_seconds' => '30',
            'step_up_window_seconds' => '300',
            'enrollment_ttl_seconds' => '900',
            'login_confirmation_email' => ' security@example.test ',
        ]);

        self::assertEquals(new AuthConfiguration(5, 60, 120, 600, 5, 5, 300, 30, 300, 900, 'security@example.test'), $result);
        self::assertTrue((new \ReflectionClass(AuthConfiguration::class))->isReadOnly());
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_invalid_auth_limits_fail_with_the_key_but_not_the_value(
        string $key,
        mixed $value,
        string $expectedEnvironmentKey,
    ): void {
        $privateValue = is_string($value) ? $value : '';
        $configuration = [
            'login_max_attempts' => '5',
            'login_decay_seconds' => '60',
            'session_lifetime_minutes' => '120',
            'login_confirmation_ttl_seconds' => '600',
            'login_confirmation_max_attempts' => '5',
            'strong_authentication_max_attempts' => '5',
            'strong_authentication_decay_seconds' => '300',
            'login_confirmation_resend_cooldown_seconds' => '30',
            'step_up_window_seconds' => '300',
            'enrollment_ttl_seconds' => '900',
            'login_confirmation_email' => null,
        ];
        $configuration[$key] = $value;

        $result = (new AuthConfigurationFactory)->inspect($configuration);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertStringContainsString($expectedEnvironmentKey, $result->message);

        if (str_contains($privateValue, 'private')) {
            self::assertStringNotContainsString($privateValue, $result->message);
        }
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'attempt typo' => ['login_max_attempts', 'five-private', 'AI6_AUTH_LOGIN_MAX_ATTEMPTS'];
        yield 'strong attempt typo' => ['strong_authentication_max_attempts', 'five-private', 'AI6_AUTH_STRONG_AUTHENTICATION_MAX_ATTEMPTS'];
        yield 'strong decay zero' => ['strong_authentication_decay_seconds', '0', 'AI6_AUTH_STRONG_AUTHENTICATION_DECAY_SECONDS'];
        yield 'decay zero' => ['login_decay_seconds', '0', 'AI6_AUTH_LOGIN_DECAY_SECONDS'];
        yield 'lifetime whitespace' => ['session_lifetime_minutes', ' 120 ', 'AI6_AUTH_SESSION_LIFETIME_MINUTES'];
        yield 'missing attempts' => ['login_max_attempts', null, 'AI6_AUTH_LOGIN_MAX_ATTEMPTS'];
    }

    public function test_invalid_configured_value_throws_the_shared_configuration_exception(): void
    {
        config([
            'ai6.auth.login_max_attempts' => 'private-invalid-limit',
            'ai6.auth.login_decay_seconds' => '60',
            'ai6.auth.session_lifetime_minutes' => '120',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('AI6_AUTH_LOGIN_MAX_ATTEMPTS');

        (new AuthConfigurationFactory)->fromConfiguredValues();
    }

    public function test_auth_configuration_does_not_add_a_security_measure_or_policy_switch(): void
    {
        self::assertCount(7, SecurityMeasure::cases());

        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(SecurityPolicy::class))->getProperties(),
        );

        sort($properties);
        self::assertSame(
            ['measureStates', 'policyHash', 'profile', 'reducedModeAcknowledged'],
            $properties,
        );
    }
}
