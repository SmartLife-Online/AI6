<?php

namespace App\AI6\Shared\Security;

use InvalidArgumentException;

final class SecurityPolicyHasher
{
    public const DOMAIN = 'AI6-SECURITY-POLICY-V1';

    public const FIELD_ORDER = [
        'profile',
        'AI6_SECURITY_LOGIN_EMAIL_CONFIRMATION',
        'AI6_SECURITY_REQUIRE_PRIVILEGED_PASSKEY',
        'AI6_SECURITY_REQUIRE_CRITICAL_ACTION_STEP_UP',
        'AI6_SECURITY_REQUIRE_HTTPS_OR_PRIVATE_ACCESS',
        'AI6_SECURITY_REQUIRE_AGENT_SANDBOX',
        'AI6_SECURITY_REQUIRE_CHECKER_NETWORK_ISOLATION',
        'AI6_SECURITY_REQUIRE_LLM_PRECOMMIT_REVIEW',
        'reduced_mode_acknowledged',
    ];

    /** @param array<string, bool> $measureStates */
    public static function hash(
        SecurityProfile $profile,
        array $measureStates,
        bool $reducedModeAcknowledged,
    ): string {
        $fields = [];

        foreach (self::FIELD_ORDER as $field) {
            $fields[] = match ($field) {
                'profile' => $profile->value,
                'reduced_mode_acknowledged' => $reducedModeAcknowledged ? '1' : '0',
                default => self::encodeMeasureState($measureStates, $field),
            };
        }

        return hash('sha256', CanonicalByteFrame::encode(self::DOMAIN, $fields));
    }

    /** @param array<string, bool> $measureStates */
    private static function encodeMeasureState(array $measureStates, string $field): string
    {
        if (! array_key_exists($field, $measureStates)) {
            throw new InvalidArgumentException(sprintf('Missing security measure state: %s.', $field));
        }

        return $measureStates[$field] ? '1' : '0';
    }
}
