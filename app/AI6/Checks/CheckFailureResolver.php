<?php

namespace App\AI6\Checks;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;

/**
 * The two authorized resolvers of a parked check_failure wait (plan §7.2).
 *
 * Every run state change still goes through the RunOrchestrator; this class
 * only adds what the check module owns: releasing the failed result so a retry
 * on the unchanged tree is a new execution instead of colliding with its own
 * predecessor. The predecessor is superseded, never deleted, so the evidence of
 * the failed check stays readable.
 */
final readonly class CheckFailureResolver
{
    public function __construct(private RunOrchestrator $orchestrator) {}

    /**
     * Retry the parked check on the unchanged tree.
     */
    public function retryOnUnchangedTree(Run $run, int $expectedVersion, string $boundStepKey, CheckPhase $phase): Run
    {
        // The where clause mirrors the update guard exactly, so the statement can
        // only perform the one-way supersede of a live, not successful result.
        CheckResultRecord::query()
            ->where('run_id', $run->getKey())
            ->where('phase', $phase->value)
            ->whereNull('superseded_at')
            ->where('state', '!=', CheckResultState::SUCCEEDED->value)
            ->update(['superseded_at' => now()]);

        return $this->orchestrator->resumeCheckFailure($run, $expectedVersion, $boundStepKey);
    }

    public function cancel(Run $run, int $expectedVersion): Run
    {
        return $this->orchestrator->cancelCheckFailure($run, $expectedVersion);
    }
}
