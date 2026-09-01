<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;

/** Central provenance and candidate binding for publish human requests. */
final class PublishHumanRequestBinding
{
    public const AGENT_SLOT = 'system:publish';

    public const AUTHORIZE_PUSH = 'authorize_push';

    public static function candidateBinding(Run $run): string
    {
        return hash('sha256', implode(':', [
            $run->id,
            (string) $run->candidate_tree_sha,
            (string) $run->candidate_diff_hash,
            (string) $run->candidate_base_sha,
        ]));
    }

    public static function statusConflictStepKey(string $runId): string
    {
        return hash('sha256', $runId.':publish-status-conflict');
    }

    public static function matchesManualPush(HumanRequest $request, Run $run): bool
    {
        return $run->candidate_tree_sha !== null
            && $run->candidate_diff_hash !== null
            && $run->candidate_base_sha !== null
            && $run->candidate_invalidated_at === null
            && $request->run_id === $run->id
            && $request->bound_agent_slot === self::AGENT_SLOT
            && hash_equals(
                RunOrchestrator::stepKey($run->id, ExecutionStepType::PUBLISH, 1),
                $request->bound_step_key,
            )
            && hash_equals(self::candidateBinding($run), $request->bound_requested_effect);
    }

    public static function matchesStatusConflict(HumanRequest $request, string $effect): bool
    {
        return $effect === 'refresh_expected_oid'
            && $request->bound_agent_slot === self::AGENT_SLOT
            && hash_equals(self::statusConflictStepKey($request->run_id), $request->bound_step_key);
    }
}
