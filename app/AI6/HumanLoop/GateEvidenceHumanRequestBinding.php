<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;

/** Provenance contract that distinguishes candidate-gate answers on the generic route. */
final class GateEvidenceHumanRequestBinding
{
    public const EFFECT = 'authorize_gate_evidence';

    private const SLOT_PREFIX = 'system:candidate-gate:';

    public static function agentSlot(string $gateId): string
    {
        return self::SLOT_PREFIX.$gateId;
    }

    public static function requestedEffect(string $treeOid, string $diffHash): string
    {
        return $treeOid.':'.$diffHash;
    }

    /** @return array{gate_id: string, tree_oid: string, diff_hash: string}|null */
    public static function binding(HumanRequest $request): ?array
    {
        if (! str_starts_with($request->bound_agent_slot, self::SLOT_PREFIX)
            || ! in_array(self::EFFECT, $request->allowed_effects, true)
            || preg_match('/\A([0-9a-f]{64}):([0-9a-f]{64})\z/D', $request->bound_requested_effect, $matches) !== 1) {
            return null;
        }
        $gateId = substr($request->bound_agent_slot, strlen(self::SLOT_PREFIX));
        if (preg_match('/\A(?:MG|EXT)-[0-9]{2}\z/D', $gateId) !== 1) {
            return null;
        }

        return ['gate_id' => $gateId, 'tree_oid' => $matches[1], 'diff_hash' => $matches[2]];
    }

    public static function matches(HumanRequest $request, string $effect): bool
    {
        return $effect === self::EFFECT && self::binding($request) !== null;
    }
}
