<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;

/** Candidate-, policy-, profile- and instruction-bound security override provenance. */
final class SecurityGateHumanRequestBinding
{
    public const EFFECT = 'security_override';

    /** Resolver name: it retries the step; only the new bound agent result may provide clear. */
    public const RETRY_EFFECT = 'bound_clear';

    private const SLOT_PREFIX = 'system:security-gate:';

    public static function agentSlot(string $profileId): string
    {
        return self::SLOT_PREFIX.$profileId;
    }

    public static function requestedEffect(
        string $tree,
        string $diff,
        string $base,
        string $instruction,
        string $policy,
        string $profileId,
    ): string {
        return implode(':', [$tree, $diff, $base, $instruction, $policy, $profileId]);
    }

    /** @return array{tree: string, diff: string, base: string, instruction: string, policy: string, profile_id: string}|null */
    public static function binding(HumanRequest $request): ?array
    {
        if (! str_starts_with($request->bound_agent_slot, self::SLOT_PREFIX)
            || ! in_array(self::EFFECT, $request->allowed_effects, true)
            || preg_match('/\A([0-9a-f]{64}):([0-9a-f]{64}):([0-9a-f]{64}):([0-9a-f]{64}):([0-9a-f]{64}):([a-z][a-z0-9._-]{0,63})\z/D', $request->bound_requested_effect, $matches) !== 1) {
            return null;
        }
        $profileId = substr($request->bound_agent_slot, strlen(self::SLOT_PREFIX));
        if (! hash_equals($profileId, $matches[6])) {
            return null;
        }

        return [
            'tree' => $matches[1],
            'diff' => $matches[2],
            'base' => $matches[3],
            'instruction' => $matches[4],
            'policy' => $matches[5],
            'profile_id' => $matches[6],
        ];
    }

    public static function matches(HumanRequest $request, string $effect): bool
    {
        return $effect === self::EFFECT && self::binding($request) !== null;
    }
}
