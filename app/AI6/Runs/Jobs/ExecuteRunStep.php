<?php

namespace App\AI6\Runs\Jobs;

use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Reviews\FindingVerificationRound;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Reviews\ReviewStallFingerprint;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\ReviewOnlyPrepareStep;
use App\AI6\Runs\ReviewOnlyRunCoordinator;
use App\AI6\Runs\RunCheckStep;
use App\AI6\Runs\RunFixTurn;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
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

    public function handle(
        RunOrchestrator $orchestrator,
        ?RunImplementation $implementation = null,
        ?RunCheckStep $checks = null,
        ?ReviewRound $reviews = null,
        ?RunFixTurn $fixes = null,
        ?RunLimitPolicy $limits = null,
        ?HumanRequestService $humanRequests = null,
        ?ReviewStallFingerprint $stallFingerprints = null,
        ?ReviewOnlyPrepareStep $reviewPrepare = null,
        ?ReviewOnlyRunCoordinator $reviewOnly = null,
        ?FindingVerificationRound $verifications = null,
    ): void {
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

        $limits ??= app(RunLimitPolicy::class);
        $humanRequests ??= app(HumanRequestService::class);
        $stallFingerprints ??= app(ReviewStallFingerprint::class);
        $exceeded = in_array($type, [
            ExecutionStepType::IMPLEMENT,
            ExecutionStepType::REVIEW,
            ExecutionStepType::FIX,
            ExecutionStepType::VERIFY,
        ], true) ? $limits->runtimeExceeded($run) : null;
        $waitReason = WaitReason::RESOURCE_LIMIT;
        if ($exceeded === null && $type === ExecutionStepType::REVIEW) {
            $exceeded = $limits->consume(
                $run,
                ImportLimit::MAX_REVIEW_ROUNDS,
                'review-round:'.$claimed->step_number,
            );
            $waitReason = WaitReason::REVIEW_LIMIT;
        }
        if ($exceeded === null && $type === ExecutionStepType::FIX) {
            $exceeded = $limits->consume(
                $run,
                ImportLimit::MAX_FIX_ROUNDS,
                'fix-round:'.$claimed->step_number,
            );
            $waitReason = WaitReason::REVIEW_LIMIT;
        }
        if ($exceeded === null && $type === ExecutionStepType::VERIFY) {
            $exceeded = $limits->consume(
                $run,
                ImportLimit::MAX_VERIFICATION_ROUNDS,
                'verification-round:'.$claimed->step_number,
            );
            $waitReason = WaitReason::REVIEW_LIMIT;
        }
        // The stall event fires once per bound fix step: a granted resolution
        // (additional round, reviewer switch or finding disposition) leaves an
        // intervention on this step key, and re-evaluating the unchanged
        // fingerprints after it would park the resumed step forever (AC-04/AC-06).
        if ($exceeded === null && $type === ExecutionStepType::FIX
            && $stallFingerprints->stalled($run, $claimed->step_number)
            && ! Intervention::query()->where('bound_step_key', $claimed->idempotency_key)
                ->whereIn('chosen_effect', ['additional_round', 'switch_reviewer', 'finding_disposition'])
                ->exists()) {
            $effective = $limits->effective($run)[ImportLimit::MAX_REVIEW_ROUNDS->value];
            $exceeded = new ImportLimitResult(ImportLimit::MAX_REVIEW_ROUNDS, $effective + 1, $effective);
            $waitReason = WaitReason::REVIEW_LIMIT;
        }
        if ($exceeded instanceof ImportLimitResult) {
            try {
                $humanRequests->openLimitRequest($run, $claimed, $exceeded, $waitReason);
            } catch (HumanRequestRejected $rejected) {
                $orchestrator->finishStep(
                    $claimed,
                    $owner,
                    ExecutionJobState::FAILED,
                    'Die Limitentscheidung konnte nicht gebunden werden.',
                    $rejected->reason,
                );
                $orchestrator->failRun($run->id);
            }

            return;
        }

        if ($type === ExecutionStepType::IMPLEMENT) {
            ($implementation ?? app(RunImplementation::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::REVIEW_PREPARE) {
            ($reviewPrepare ?? app(ReviewOnlyPrepareStep::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::CHECK) {
            ($checks ?? app(RunCheckStep::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::REVIEW) {
            ($reviews ?? app(ReviewRound::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::VERIFY) {
            ($verifications ?? app(FindingVerificationRound::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::REPORT) {
            ($reviewOnly ?? app(ReviewOnlyRunCoordinator::class))->execute($claimed, $run, $owner);

            return;
        }

        if ($type === ExecutionStepType::FIX) {
            ($fixes ?? app(RunFixTurn::class))->execute($claimed, $run, $owner);

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

        if (! $orchestrator->applyPreparedStepEffect($run, ExecutionStepType::PREFLIGHT, $job->step_number)) {
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
