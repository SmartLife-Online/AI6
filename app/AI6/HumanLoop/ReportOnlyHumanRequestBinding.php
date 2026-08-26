<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;

/** Central provenance contract for report-only human requests. */
final class ReportOnlyHumanRequestBinding
{
    public const AGENT_SLOT = 'system:report-only';

    public static function completionStepKey(string $runId): string
    {
        return hash('sha256', $runId.':report-only-completion');
    }

    public static function statusConflictStepKey(string $runId): string
    {
        return hash('sha256', $runId.':report-status-conflict');
    }

    public static function matches(HumanRequest $request, string $effect): bool
    {
        if ($request->bound_agent_slot !== self::AGENT_SLOT) {
            return false;
        }

        return match ($effect) {
            'confirm_report' => hash_equals(self::completionStepKey($request->run_id), $request->bound_step_key),
            'refresh_expected_oid' => hash_equals(self::statusConflictStepKey($request->run_id), $request->bound_step_key),
            default => false,
        };
    }
}
