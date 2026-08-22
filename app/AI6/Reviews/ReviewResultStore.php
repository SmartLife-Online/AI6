<?php

namespace App\AI6\Reviews;

use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use Illuminate\Support\Str;

/** Append-only persistence for one review invocation. */
final readonly class ReviewResultStore
{
    /** @param array<string, mixed> $bindings */
    public function append(
        Run $run,
        RunAgent $slot,
        int $round,
        int $attempt,
        ReviewInvocationOutcome $outcome,
        array $bindings,
        ?string $failureCode = null,
        ?string $resultStatus = null,
        ?string $artifactId = null,
    ): ReviewResult {
        return ReviewResult::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => $run->id,
            'round_number' => $round,
            'slot_id' => $slot->slot_id,
            'attempt' => $attempt,
            'role' => $slot->role,
            'provider_profile' => $slot->provider_profile,
            'model' => $slot->model,
            'effort' => $slot->effort,
            'prompt_profile' => $slot->prompt_profile,
            'session_id' => $slot->session_id,
            ...$bindings,
            'invocation_outcome' => $outcome,
            'failure_code' => $failureCode,
            'result_status' => $resultStatus,
            'raw_artifact_id' => $artifactId,
        ]);
    }

    public function attempt(Run $run, int $round, string $slotId): int
    {
        return (int) ReviewResult::query()->where('run_id', $run->id)
            ->where('round_number', $round)->where('slot_id', $slotId)->max('attempt') + 1;
    }

    public function terminalOutcome(Run $run, int $round, string $slotId): ?ReviewInvocationOutcome
    {
        $outcomes = ReviewResult::query()->where('run_id', $run->id)
            ->where('round_number', $round)->where('slot_id', $slotId)
            ->orderByDesc('attempt')->pluck('invocation_outcome');
        foreach ($outcomes as $value) {
            $outcome = $value instanceof ReviewInvocationOutcome
                ? $value
                : (is_string($value) ? ReviewInvocationOutcome::tryFrom($value) : null);
            if ($outcome?->terminal() === true) {
                return $outcome;
            }
        }

        return null;
    }

    public function expectedWorkspaceHash(Run $run, int $round): ?string
    {
        $value = ReviewResult::query()->where('run_id', $run->id)->where('round_number', $round)
            ->whereNotNull('workspace_tree_hash')
            ->orderBy('created_at')->orderBy('attempt')->orderBy('id')
            ->value('workspace_tree_hash');

        return is_string($value) ? $value : null;
    }
}
