<?php

namespace App\AI6\Runs;

use App\AI6\Git\ReviewSubjectException;
use App\AI6\Git\ReviewSubjectNormalizer;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketV1Parser;
use Throwable;

final readonly class ReviewOnlyPrepareStep
{
    public function __construct(
        private ReviewSubjectNormalizer $subjects,
        private RunOrchestrator $orchestrator,
        private HumanRequestService $humanRequests,
        private TicketV1Parser $tickets,
    ) {}

    public function execute(ExecutionJob $job, Run $run, string $owner): void
    {
        // The effect of this step is applied before its result is published, so
        // a crash in between leaves a claimable job on an already advanced run.
        // A redelivery then has to complete the publication idempotently — the
        // bound review subject proves the effect happened — instead of failing a
        // run that is doing exactly what it should (RUN-003).
        if ($run->run_type !== RunType::REVIEW_ONLY
            || ($run->phase !== RunPhase::PREPARE && $run->review_subject_kind === null)) {
            $this->fail($job, $run, $owner, 'review_prepare_invalid');

            return;
        }
        $intent = [
            'effect' => 'normalize_review_subject',
            'run_id' => $run->id,
            'source_reference_hash' => hash('sha256', (string) $run->review_subject_reference),
            'idempotency_key' => $job->idempotency_key,
        ];
        if ($job->intent === null) {
            if (! $this->orchestrator->persistIntent($job, $owner, $intent)) {
                return;
            }
        } elseif ($this->decode($job->intent) !== $intent) {
            $this->fail($job, $run, $owner, 'invalid_step_intent');

            return;
        }

        try {
            $project = $run->project()->firstOrFail();
            if (! is_string($project->project_identifier) || $project->project_identifier === '') {
                throw new ReviewSubjectException('managed_project_missing');
            }
            $context = new RedactionContext((string) $run->project_id, $run->id, 'review-subject-normalization');
            $run = $this->subjects->normalize($run, $project->project_identifier, $context);
            $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
            $readModel = TicketReadModel::query()->where('project_id', $run->project_id)
                ->where('relative_path', $approval->relative_path)
                ->where('ticket_contract_sha256', $run->ticket_contract_sha256 ?? $approval->ticket_contract_sha256)
                ->latest('generated_at')->firstOrFail();
            $this->orchestrator->prepareGates($run, $this->tickets->parse($readModel->redacted_content), $readModel->ticket_contract_sha256);
        } catch (ReviewSubjectException $exception) {
            $this->parkOnDrift($job, $run, $owner, $exception->reason);

            return;
        } catch (Throwable) {
            $this->fail($job, $run, $owner, 'review_prepare_failed');

            return;
        }

        if ($this->orchestrator->applyPreparedStepEffect($run, ExecutionStepType::REVIEW_PREPARE, $job->step_number)) {
            $this->orchestrator->finishStep($job, $owner, ExecutionJobState::SUCCEEDED, 'Reviewgegenstand gebunden und materialisiert.');
        }
    }

    /** @return array<string, mixed>|null */
    private function decode(string $intent): ?array
    {
        try {
            $value = json_decode($intent, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    private function parkOnDrift(ExecutionJob $job, Run $run, string $owner, string $reason): void
    {
        $this->orchestrator->recordStepEvent($run->id, $job->step_type, ExecutionJobState::WAITING, 'Reviewquelle blockiert: '.$reason.'.');
        try {
            $fresh = $run->fresh() ?? $run;
            $parked = $this->orchestrator->parkOnBaseDrift($fresh, $fresh->version);
            $this->humanRequests->openBaseDriftRequest($parked);
        } catch (HumanRequestRejected|RunTransitionConflict) {
        }
        $this->orchestrator->parkStep($job, $owner);
    }

    private function fail(ExecutionJob $job, Run $run, string $owner, string $reason): void
    {
        $this->orchestrator->finishStep($job, $owner, ExecutionJobState::FAILED, 'Reviewvorbereitung fehlgeschlagen.', $reason);
        $this->orchestrator->failRun($run->id);
    }
}
