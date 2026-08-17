<?php

namespace App\AI6\Runs;

use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bring execution steps that lost their delivery back into a defined state.
 *
 * The worker queue gives up after its own bounded tries, so an expired lease, a
 * crashed attempt and a step parked behind a waiting run would otherwise stay
 * invisible forever. This reconciler is the only redelivery path; every decision
 * it applies runs through `RunOrchestrator`.
 */
final readonly class RunStepReconciler
{
    /** Bounded work per tick so a large backlog cannot starve the scheduler. */
    private const BUDGET = 16;

    public function __construct(
        private RunOrchestrator $orchestrator,
        private RunStepConfiguration $configuration,
    ) {}

    public function reconcile(): void
    {
        $now = now();
        $stalePlanned = $now->copy()->subSeconds($this->configuration->leaseSeconds);

        $query = ExecutionJob::query();
        $query->where(static function (Builder $group) use ($now, $stalePlanned): void {
            $group->where(static function (Builder $planned) use ($stalePlanned): void {
                $planned->where('state', ExecutionJobState::PLANNED->value)
                    ->where('updated_at', '<=', $stalePlanned);
            })->orWhere(static function (Builder $expired) use ($now): void {
                $expired->where('state', ExecutionJobState::RUNNING->value)
                    ->where('lease_expires_at', '<=', $now);
            })->orWhere('state', ExecutionJobState::WAITING->value);
        })->orderBy('id')->limit(self::BUDGET);

        foreach ($query->get() as $job) {
            $run = Run::query()->find($job->run_id);
            $type = ExecutionStepType::tryFrom($job->step_type);
            // A prepared step whose consumer ticket has not registered its handler
            // yet stays prepared; redelivering it would only churn the queue.
            if (! $run instanceof Run || ! $type instanceof ExecutionStepType || ! $type->hasRegisteredHandler()) {
                continue;
            }

            if ($job->state === ExecutionJobState::WAITING) {
                if ($run->state !== RunState::WAITING && $this->orchestrator->resumeStep($job)) {
                    $this->orchestrator->redeliverStep($job);
                }

                continue;
            }

            if ($job->attempts >= $this->configuration->maxAttempts) {
                $this->orchestrator->failExhaustedStep($job);

                continue;
            }

            if ($job->state === ExecutionJobState::RUNNING && ! $this->orchestrator->reclaimExpiredStep($job)) {
                continue;
            }

            $this->orchestrator->redeliverStep($job);
        }
    }
}
