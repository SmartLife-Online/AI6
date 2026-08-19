<?php

namespace App\AI6\Runs\Jobs;

use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JsonException;

/**
 * Execute exactly one run step.
 *
 * The job owns no decision of its own: it claims its step through the
 * orchestrator's compare-and-swap, applies the bound side effect before it
 * publishes the result, and leaves every state change to the orchestrator.
 */
final class ExecuteRunStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(public readonly int $executionJobId) {}

    public function handle(RunOrchestrator $orchestrator, ?RunImplementation $implementation = null, ?RunCheckStep $checks = null): void
    {
        $job = ExecutionJob::query()->find($this->executionJobId);
        if (! $job instanceof ExecutionJob
            || in_array($job->state, [ExecutionJobState::SUCCEEDED, ExecutionJobState::FAILED], true)) {
            return;
        }

        $type = ExecutionStepType::tryFrom($job->step_type);
        if (! $type instanceof ExecutionStepType || ! $type->hasRegisteredHandler()) {
            return;
        }

        $owner = 'worker:'.(gethostname() ?: 'unknown').':'.bin2hex(random_bytes(8));
        $claimed = $orchestrator->claimStep($job, $owner);
        if (! $claimed instanceof ExecutionJob) {
            $orchestrator->failExhaustedStep($job->fresh() ?? $job);

            return;
        }

        $run = Run::query()->find($claimed->run_id);
        if (! $run instanceof Run) {
            $orchestrator->finishStep($claimed, $owner, ExecutionJobState::FAILED, 'Der Run des Schritts ist nicht mehr vorhanden.', 'run_missing');

            return;
        }
        if (! in_array($run->state, [RunState::QUEUED, RunState::RUNNING], true)) {
            $this->abandon($orchestrator, $claimed, $run->id, $owner);

            return;
        }

        if ($type === ExecutionStepType::IMPLEMENT) {
            ($implementation ?? app(RunImplementation::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::CHECK) {
            ($checks ?? app(RunCheckStep::class))->execute($claimed, $run, $owner);

            return;
        }

        $this->preflight($orchestrator, $claimed, $run, $owner);
    }

    private function preflight(RunOrchestrator $orchestrator, ExecutionJob $job, Run $run, string $owner): void
    {
        $failureCode = $orchestrator->preflightFailureCode($run);
        if ($failureCode !== null) {
            $orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Preflight ist fehlgeschlagen: '.$failureCode.'.', $failureCode);
            $orchestrator->failRun($run->id);

            return;
        }

        $intent = $this->boundIntent($run);
        if ($job->intent === null) {
            if (! $orchestrator->persistIntent($job, $owner, $intent)) {
                // The lease was taken over; the reconciler redelivers the step.
                return;
            }
        } elseif (! $this->intentMatches($job->intent, $intent)) {
            $orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Schritt-Intent ist nicht gebunden.', 'invalid_step_intent');
            $orchestrator->failRun($run->id);

            return;
        }

        if (! $orchestrator->applyPreparedStepEffect($run, ExecutionStepType::PREFLIGHT)) {
            // The run left the executable range while this step was claimed. Nothing
            // was applied, so the step must not publish a success for it either.
            $this->abandon($orchestrator, $job, $run->id, $owner);

            return;
        }

        $orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Preflight abgeschlossen.');
    }

    /**
     * Give up a claimed step whose run is no longer executable.
     *
     * A waiting run keeps its step parked, so the reconciler picks it up again once
     * the wait ends; a terminal or vanished run ends the step as a named failure.
     */
    private function abandon(RunOrchestrator $orchestrator, ExecutionJob $job, string $runId, string $owner): void
    {
        $run = Run::query()->find($runId);
        if ($run instanceof Run && $run->state === RunState::WAITING) {
            $orchestrator->parkStep($job, $owner);

            return;
        }

        $orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Run ist nicht mehr ausführbar.', 'run_not_executable');
    }

    /** @return array<string, scalar> */
    private function boundIntent(Run $run): array
    {
        return [
            'effect' => 'prepare_implement_step',
            'run_id' => $run->id,
            'step_type' => ExecutionStepType::IMPLEMENT->value,
            'step_number' => 1,
            'idempotency_key' => RunOrchestrator::stepKey($run->id, ExecutionStepType::IMPLEMENT, 1),
        ];
    }

    /** @param  array<string, scalar>  $expected */
    private function intentMatches(string $stored, array $expected): bool
    {
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return $decoded === $expected;
    }
}
