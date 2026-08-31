<?php

namespace App\AI6\Runs;

use App\AI6\Git\PublishCandidate;
use App\AI6\Git\PublishCandidateException;
use App\AI6\Git\PublishCandidateService;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use Throwable;

/** Final checks, deterministic prospect, one gate, and atomic candidate binding. */
final readonly class RunFinalizationStep
{
    public function __construct(
        private RunCheckStep $checkStep,
        private PublishCandidateService $candidates,
        private CandidateGate $gate,
        private RunOrchestrator $runs,
        private HumanRequestService $humanRequests,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        if (! $this->checkStep->executeFinalChecks($job, $run, $owner)) {
            return;
        }
        $run = $run->fresh() ?? $run;
        try {
            $candidate = $this->candidates->prospect($run);
        } catch (PublishCandidateException $exception) {
            $this->rejectCandidate($job, $run, $owner, $exception);

            return;
        }

        $decision = $this->gate->decide($run, $candidate);
        if ($decision->blockers !== []) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Das Candidate-Gate ist blockiert.', $decision->blockers[0]);
            $this->runs->failRun($run->id);

            return;
        }
        if ($decision->openGates !== []) {
            $this->parkManualGate($job, $run, $owner, $candidate, $decision->openGates[0]);

            return;
        }

        try {
            $run = $this->candidates->bind($run, $candidate);
        } catch (PublishCandidateException $exception) {
            if ($exception->reason === 'candidate_expectation_mismatch') {
                try {
                    $actual = $this->candidates->prospect($run->fresh() ?? $run);
                    $decision = $this->gate->decide($run->fresh() ?? $run, $actual);
                    if ($decision->openGates !== []) {
                        $this->parkManualGate($job, $run->fresh() ?? $run, $owner, $actual, $decision->openGates[0]);

                        return;
                    }
                } catch (Throwable) {
                    // The named expectation failure below remains the safe terminal outcome.
                }
            }
            $this->rejectCandidate($job, $run, $owner, $exception);

            return;
        } catch (RunTransitionConflict $exception) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Publish-Kandidat konnte nicht gebunden werden.', $exception->reason);
            $this->runs->failRun($run->id);

            return;
        }

        if (! $this->runs->applyPreparedStepEffect($run, ExecutionStepType::FINALIZE, $job->step_number)) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Run ist nicht mehr ausführbar.', 'run_not_executable');

            return;
        }
        $this->runs->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Finalchecks und Publish-Kandidat sind gebunden.');
    }

    private function parkManualGate(
        ExecutionJob $job,
        Run $run,
        string $owner,
        PublishCandidate $candidate,
        string $gateId,
    ): void {
        $gate = RunGate::query()->where('run_id', $run->id)->where('gate_id', $gateId)->first();
        if (! $gate instanceof RunGate) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Das offene Gate ist nicht mehr auffindbar.', 'candidate_gate_missing');
            $this->runs->failRun($run->id);

            return;
        }
        try {
            $this->humanRequests->openManualGateRequest($run, $job, $gate, $candidate);
        } catch (HumanRequestRejected $exception) {
            $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Gate-Request konnte nicht gebunden werden.', $exception->reason);
            $this->runs->failRun($run->id);
        }
    }

    private function rejectCandidate(
        ExecutionJob $job,
        Run $run,
        string $owner,
        PublishCandidateException $exception,
    ): void {
        if ($exception->reason === 'control_head_drift') {
            try {
                $fresh = $run->fresh() ?? $run;
                $parked = $this->runs->parkOnBaseDrift($fresh, $fresh->version);
            } catch (Throwable $parkFailure) {
                // The original drift remains the named failure if parking cannot be completed.
                report($parkFailure);
                $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Publish-Kandidat wurde verworfen.', $exception->reason);
                $this->runs->failRun($run->id);

                return;
            }
            $this->runs->parkStep($job, $owner);
            try {
                $this->humanRequests->openBaseDriftRequest($parked);
            } catch (Throwable $requestFailure) {
                // The durable base-drift wait is the safety boundary. A failed
                // notification/request path must not turn it into a terminal run.
                report($requestFailure);
            }

            return;
        }
        $this->runs->finishStep($job, $owner, ExecutionJobState::FAILED, 'Der Publish-Kandidat wurde verworfen.', $exception->reason);
        $this->runs->failRun($run->id);
    }
}
