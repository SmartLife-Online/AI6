<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\RunBranchName;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\FindingDispositionSource;
use App\AI6\Reviews\FindingDispositionType;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunCheckpoint;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Tickets\TicketDocument;
use App\AI6\Tickets\TicketStatusOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The exclusive mutation boundary for persisted run state. */
final readonly class RunOrchestrator
{
    public function __construct(
        private RunTransitionMap $transitions,
        private Redactor $redactor,
        private RunStepConfiguration $stepConfiguration,
        private RunPreflight $preflight,
        private ExecutionStepDispatcher $dispatcher,
        private ScopeReconciliation $scopeReconciliation,
        private ProjectPolicy $projectPolicy,
        private EffectiveFindingState $findingState,
    ) {}

    public function recordHumanFindingDisposition(
        Run $run,
        Finding $finding,
        int $expectedVersion,
        FindingDispositionType $type,
        string $reason,
        User $actor,
        string $stepUpProofHash,
    ): Run {
        if (! $type->isHumanOverride() || trim($reason) === '' || $finding->run_id !== $run->id) {
            throw new RunTransitionConflict('finding_disposition_invalid', 'The finding disposition is invalid.');
        }
        $project = Project::query()->findOrFail($run->project_id);
        if (! $this->projectPolicy->decide(ProjectAction::DISPOSE_FINDING, $actor, $project)) {
            throw new RunTransitionConflict('finding_disposition_unauthorized', 'The actor cannot dispose findings.');
        }
        // The policy above owns the authorization decision; the membership is read only to
        // record which role decided, never to decide a second time.
        $membership = ProjectMembership::query()->where('project_id', $project->id)
            ->where('user_id', $actor->getKey())->first();
        if (! $membership instanceof ProjectMembership) {
            throw new RunTransitionConflict('finding_disposition_unauthorized', 'The actor has no project membership.');
        }

        $requestHash = hash('sha256', implode(':', [
            $finding->id, $type->value, trim($reason), $actor->getKey(), $expectedVersion,
        ]));
        if (FindingDisposition::query()->where('request_hash', $requestHash)->exists()) {
            return Run::query()->findOrFail($run->id);
        }

        return DB::transaction(function () use ($run, $finding, $expectedVersion, $type, $reason, $actor, $membership, $stepUpProofHash, $requestHash): Run {
            DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
            $fresh = Run::query()->findOrFail($run->id);
            if ($fresh->version !== $expectedVersion) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before the finding disposition was recorded.');
            }
            FindingDisposition::query()->create([
                'id' => (string) Str::uuid(),
                'finding_id' => $finding->id,
                'type' => $type,
                'reason' => trim($reason),
                'decision_source' => FindingDispositionSource::HUMAN_OVERRIDE,
                'decided_by' => $actor->getKey(),
                'decision_role' => $membership->role->value,
                'step_up_proof_hash' => $stepUpProofHash,
                'expected_run_version' => $expectedVersion,
                'request_hash' => $requestHash,
                ...$this->findingDispositionBindings($fresh, $finding),
            ]);
            $updated = Run::query()->whereKey($fresh->id)->where('version', $expectedVersion)
                ->update(['version' => $expectedVersion + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before the finding disposition was recorded.');
            }

            return Run::query()->findOrFail($fresh->id);
        });
    }

    public function recordFixedFinding(
        Run $run,
        Finding $finding,
        ReviewResult $evidence,
        int $expectedVersion,
        string $reason,
    ): Run {
        if (trim($reason) === '' || $finding->run_id !== $run->id || $evidence->run_id !== $run->id
            || $evidence->invocation_outcome !== ReviewInvocationOutcome::VALID_RESULT
            || $evidence->id === $finding->review_result_id
            || $evidence->result_status !== 'nothing_to_fix'
            || ! hash_equals((string) $run->checkpoint_tree_sha, $evidence->checkpoint_tree_sha)
            || ! hash_equals((string) $run->checkpoint_diff_hash, $evidence->diff_hash)
            || hash_equals($finding->checkpoint_tree_sha, $evidence->checkpoint_tree_sha)) {
            throw new RunTransitionConflict('fixed_evidence_invalid', 'The fixed disposition lacks current server review evidence.');
        }

        return DB::transaction(function () use ($run, $finding, $evidence, $expectedVersion, $reason): Run {
            DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
            $fresh = Run::query()->findOrFail($run->id);
            if ($fresh->version !== $expectedVersion || $fresh->checkpoint_tree_sha !== $evidence->checkpoint_tree_sha
                || $fresh->checkpoint_diff_hash !== $evidence->diff_hash
                || $fresh->checkpoint_tree_sha === $finding->checkpoint_tree_sha) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before fixed evidence was recorded.');
            }
            $requestHash = hash('sha256', implode(':', [$finding->id, $evidence->id, $expectedVersion, trim($reason)]));
            FindingDisposition::query()->firstOrCreate(['request_hash' => $requestHash], [
                'id' => (string) Str::uuid(),
                'finding_id' => $finding->id,
                'type' => FindingDispositionType::FIXED,
                'reason' => trim($reason),
                'decision_source' => FindingDispositionSource::SERVER_REVIEW,
                'evidence_review_result_id' => $evidence->id,
                'expected_run_version' => $expectedVersion,
                ...$this->findingDispositionBindings($fresh, $finding),
            ]);
            $updated = Run::query()->whereKey($fresh->id)->where('version', $expectedVersion)
                ->update(['version' => $expectedVersion + 1, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before fixed evidence was recorded.');
            }

            return Run::query()->findOrFail($fresh->id);
        });
    }

    /** @return array<string, string> */
    private function findingDispositionBindings(Run $run, Finding $finding): array
    {
        $ticketContract = $run->ticket_contract_sha256
            ?? TicketApproval::query()->whereKey($run->ticket_approval_id)->value('ticket_contract_sha256');
        $bindings = [
            'ticket_contract_sha256' => $ticketContract,
            'config_hash' => $run->config_hash,
            'scope_hash' => $run->scope_hash,
            'prompt_hash' => $run->prompt_hash,
            'instruction_hash' => $run->instruction_hash,
            'runtime_profile_hash' => $run->runtime_profile_hash,
            'agent_profile_hash' => $run->agent_profile_hash,
            'security_policy_hash' => $run->security_policy_hash,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'reviewer_binding_hash' => $this->findingState->reviewerBindingHash($finding),
        ];
        foreach ($bindings as $field => $value) {
            if (! is_string($value)) {
                throw new RunTransitionConflict('finding_binding_missing_'.$field, 'The run lacks a finding disposition binding.');
            }
        }

        /** @var array<string, string> $bindings */
        return $bindings;
    }

    /**
     * Derive the stable idempotency key of a step.
     *
     * The key is a pure function of run, step type and step number, so the same
     * step can never be planned twice behind the unique index on the column.
     */
    public static function stepKey(string $runId, ExecutionStepType $type, int $number): string
    {
        return hash('sha256', implode(':', [$runId, $type->value, $number]));
    }

    public function nextStep(Run $run, ?ExecutionStepType $completing = null, ?int $completedNumber = null): ?ExecutionStepType
    {
        return $this->nextStepPlan($run, $completing, $completedNumber)['type'] ?? null;
    }

    /**
     * Plan the step the next-step decision names and hand it to the queue.
     *
     * This is the only way an execution job is created; the decision itself never
     * happens outside this class.
     */
    public function planNextStep(Run $run, ?ExecutionStepType $completing = null, ?int $completedNumber = null): ?ExecutionJob
    {
        $plan = $this->nextStepPlan($run, $completing, $completedNumber);
        if ($plan === null) {
            return null;
        }

        return $this->ensureStep($run, $plan['type'], $plan['number']);
    }

    /** @return array{type: ExecutionStepType, number: int}|null */
    private function nextStepPlan(Run $run, ?ExecutionStepType $completing, ?int $completedNumber = null): ?array
    {
        if (in_array($run->state, [RunState::FAILED, RunState::COMPLETED, RunState::CANCELLED, RunState::WAITING], true)
            || $run->wait_reason instanceof WaitReason) {
            return null;
        }
        $completed = [];
        foreach (ExecutionJob::query()->where('run_id', $run->id)
            ->where('state', ExecutionJobState::SUCCEEDED->value)->get(['step_type', 'step_number']) as $job) {
            $completed[$job->step_type.':'.$job->step_number] = true;
        }
        if ($completing instanceof ExecutionStepType) {
            $number = $completedNumber ?? (int) ExecutionJob::query()->where('run_id', $run->id)
                ->where('step_type', $completing->value)->where('state', ExecutionJobState::RUNNING->value)->value('step_number');
            if ($number > 0) {
                $completed[$completing->value.':'.$number] = true;
            }
        }

        return self::decideNextStepRound($run->state, $run->wait_reason, array_keys($completed), $this->hasEffectiveBlockingFindings($run));
    }

    /**
     * Pure round-aware form of the one next-step decision.
     *
     * @param  list<string>  $completedCoordinates  `step_type:round` values
     * @return array{type: ExecutionStepType, number: int}|null
     */
    public static function decideNextStepRound(
        RunState $state,
        ?WaitReason $waitReason,
        array $completedCoordinates,
        bool $hasEffectiveBlocker,
    ): ?array {
        if (in_array($state, [RunState::FAILED, RunState::COMPLETED, RunState::CANCELLED, RunState::WAITING], true)
            || $waitReason instanceof WaitReason) {
            return null;
        }
        $completed = array_fill_keys($completedCoordinates, true);
        foreach ([ExecutionStepType::PREFLIGHT, ExecutionStepType::IMPLEMENT, ExecutionStepType::CHECK, ExecutionStepType::REVIEW] as $type) {
            if (! isset($completed[$type->value.':1'])) {
                return ['type' => $type, 'number' => 1];
            }
        }
        $reviewRound = 1;
        while (isset($completed[ExecutionStepType::REVIEW->value.':'.($reviewRound + 1)])) {
            $reviewRound++;
        }
        if (! isset($completed[ExecutionStepType::FIX->value.':'.$reviewRound])) {
            return $hasEffectiveBlocker
                ? ['type' => ExecutionStepType::FIX, 'number' => $reviewRound]
                : null;
        }
        $nextRound = $reviewRound + 1;
        if (! isset($completed[ExecutionStepType::CHECK->value.':'.$nextRound])) {
            return ['type' => ExecutionStepType::CHECK, 'number' => $nextRound];
        }
        if (! isset($completed[ExecutionStepType::REVIEW->value.':'.$nextRound])) {
            return ['type' => ExecutionStepType::REVIEW, 'number' => $nextRound];
        }

        return null;
    }

    public function hasEffectiveBlockingFindings(Run $run): bool
    {
        foreach (Finding::query()->with('dispositions')->where('run_id', $run->id)->get() as $finding) {
            if ($this->findingState->blocks($finding, $run)) {
                return true;
            }
        }

        return false;
    }

    public function preflightFailureCode(Run $run): ?string
    {
        return $this->preflight->failureCode($run);
    }

    private function ensureStep(Run $run, ExecutionStepType $type, int $number = 1): ExecutionJob
    {
        $key = self::stepKey($run->getKey(), $type, $number);

        $job = ExecutionJob::query()->firstOrCreate(
            ['idempotency_key' => $key],
            ['run_id' => $run->getKey(), 'step_type' => $type->value, 'step_number' => $number, 'state' => ExecutionJobState::PLANNED],
        );
        if ($job->wasRecentlyCreated) {
            $this->recordStepEvent($run->id, $type->value, ExecutionJobState::PLANNED, 'Schritt geplant.', 'planned:'.$key);
            if ($type->hasRegisteredHandler()) {
                $dispatcher = $this->dispatcher;
                DB::afterCommit(static function () use ($dispatcher, $job): void {
                    $dispatcher->dispatch($job);
                });
            }
        }

        return $job;
    }

    /**
     * Apply the bound side effect of a prepared step before its result is published.
     *
     * Every part of it is idempotent, so a crash between effect and publication is
     * completed from the persisted intent without repeating the effect. Returns
     * false when the run left the executable range while the step was claimed; the
     * effect is then not applied, and the caller must not publish a success for it.
     */
    public function applyPreparedStepEffect(Run $run, ExecutionStepType $completed, ?int $completedNumber = null): bool
    {
        $fresh = Run::query()->findOrFail($run->getKey());
        if ($fresh->pending_status_operation_id !== null
            || ! in_array($fresh->state, [RunState::QUEUED, RunState::RUNNING], true)) {
            return false;
        }
        if ($completed === ExecutionStepType::PREFLIGHT && $fresh->state === RunState::QUEUED) {
            $fresh = $this->transition($fresh, $fresh->version, RunState::RUNNING, RunPhase::IMPLEMENT);
        }
        if ($completed === ExecutionStepType::IMPLEMENT && $fresh->phase === RunPhase::IMPLEMENT) {
            $fresh = $this->advancePhase($fresh, $fresh->version, RunPhase::CHECK);
        }
        if ($completed === ExecutionStepType::CHECK && $fresh->phase === RunPhase::CHECK
            && $fresh->review_readiness_state === 'ready') {
            $fresh = $this->advancePhase($fresh, $fresh->version, RunPhase::REVIEW);
        }
        if ($completed === ExecutionStepType::REVIEW && $fresh->phase === RunPhase::REVIEW
            && $this->hasEffectiveBlockingFindings($fresh)) {
            $fresh = $this->advancePhase($fresh, $fresh->version, RunPhase::FIX);
        }
        if ($completed === ExecutionStepType::FIX && $fresh->phase === RunPhase::FIX) {
            $fresh = $this->advancePhase($fresh, $fresh->version, RunPhase::CHECK);
        }

        $this->planNextStep($fresh, $completed, $completedNumber);

        return true;
    }

    /**
     * Move an executable run into its next phase without changing its state.
     *
     * The generic state map deliberately has no running → running edge, so a
     * phase-only advance cannot go through `transition()`. It stays a
     * compare-and-swap on the run version and applies only while the run is
     * still executable and not waiting.
     */
    public function advancePhase(Run $run, int $expectedVersion, RunPhase $phase): Run
    {
        $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)
            ->whereIn('state', [RunState::QUEUED->value, RunState::RUNNING->value])
            ->whereNull('wait_reason')
            ->update(['phase' => $phase, 'version' => $expectedVersion + 1]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before its phase could be advanced.');
        }

        return Run::query()->findOrFail($run->getKey());
    }

    public function claimStep(ExecutionJob $job, string $owner): ?ExecutionJob
    {
        $now = now();
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('attempts', '<', $this->stepConfiguration->maxAttempts)
            ->where(function ($query) use ($now): void {
                $query->where('state', ExecutionJobState::PLANNED->value)
                    ->orWhere(function ($expired) use ($now): void {
                        $expired->where('state', ExecutionJobState::RUNNING->value)->where('lease_expires_at', '<=', $now);
                    });
            })->update([
                'state' => ExecutionJobState::RUNNING,
                'lease_owner' => $owner,
                'lease_expires_at' => $now->copy()->addSeconds($this->stepConfiguration->leaseSeconds),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $claimed = ExecutionJob::query()->find($job->getKey());
        if (! $claimed instanceof ExecutionJob) {
            return null;
        }
        $this->recordStepEvent($claimed->run_id, $claimed->step_type, ExecutionJobState::RUNNING, 'Schritt beansprucht.', 'running:'.$claimed->id.':'.$claimed->attempts);

        return $claimed;
    }

    /**
     * End a step that has no attempt left as visibly failed.
     *
     * A step is exhausted once it holds the configured attempt maximum and is not
     * owned by a live lease any more; the run follows it into a named failure.
     */
    public function failExhaustedStep(ExecutionJob $job): bool
    {
        $now = now();
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('attempts', '>=', $this->stepConfiguration->maxAttempts)
            ->where(function ($query) use ($now): void {
                $query->where('state', ExecutionJobState::PLANNED->value)
                    ->orWhere(function ($expired) use ($now): void {
                        $expired->where('state', ExecutionJobState::RUNNING->value)->where('lease_expires_at', '<=', $now);
                    });
            })->update([
                'state' => ExecutionJobState::FAILED,
                'failure_code' => 'step_retry_exhausted',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            return false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::FAILED, 'Schrittversuche ausgeschöpft: step_retry_exhausted.', 'failed:'.$job->id.':'.$job->attempts);
        $this->failRun($job->run_id);

        return true;
    }

    /** Park a claimed step while its run waits for a decision. */
    public function parkStep(ExecutionJob $job, string $owner): bool
    {
        return $this->finishStep($job, $owner, ExecutionJobState::WAITING, 'Schritt wartet auf eine Entscheidung.');
    }

    /** Park an asynchronous poll without charging it as another execution attempt. */
    public function parkPollingStep(ExecutionJob $job, string $owner): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_owner', $owner)
            ->where('attempts', '>', 0)
            ->update([
                'state' => ExecutionJobState::WAITING,
                'failure_code' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'attempts' => DB::raw('attempts - 1'),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }
        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::WAITING, 'Schritt wartet auf ein gebundenes externes Ergebnis.', 'polling:'.$job->id);

        return true;
    }

    /** Return a parked step to the planned state once its run no longer waits. */
    public function resumeStep(ExecutionJob $job, bool $clearIntent = false): bool
    {
        $values = [
            'state' => ExecutionJobState::PLANNED,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'updated_at' => now(),
        ];
        if ($clearIntent) {
            $values['intent'] = null;
        }
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::WAITING->value)->update($values);
        if ($updated !== 1) {
            return false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::PLANNED, 'Schritt erneut geplant.', 'planned:'.$job->id.':'.$job->attempts);

        return true;
    }

    /** Replan a claimed check step after its evidence binding changed in-place. */
    public function replanStepAfterEvidenceChange(ExecutionJob $job, string $owner): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_owner', $owner)
            ->where('attempts', '>', 0)
            ->update([
                'state' => ExecutionJobState::PLANNED,
                'intent' => null,
                'failure_code' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'attempts' => DB::raw('attempts - 1'),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }

        $fresh = ExecutionJob::query()->findOrFail($job->getKey());
        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::PLANNED, 'Schritt nach Evidenzänderung erneut geplant.', 'evidence-replan:'.$job->id.':'.$fresh->attempts);
        $this->redeliverStep($fresh);

        return true;
    }

    /** Return a step whose lease died to the planned state, owner-independently. */
    public function reclaimExpiredStep(ExecutionJob $job): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_expires_at', '<=', now())->update([
                'state' => ExecutionJobState::PLANNED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::PLANNED, 'Abgelaufenes Lease zurückgegeben.', 'planned:'.$job->id.':'.$job->attempts);

        return true;
    }

    /**
     * Hand a planned step back to the queue.
     *
     * The delivery stamp moves forward so the reconciler waits a full lease period
     * before it delivers the same step again; a worker that stays down therefore
     * cannot be answered with an unbounded pile of duplicate messages.
     */
    public function redeliverStep(ExecutionJob $job): void
    {
        ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::PLANNED->value)
            ->update(['updated_at' => now()]);

        $this->dispatcher->dispatch($job);
    }

    /**
     * Move a non-terminal run into the failed state without releasing the project lock.
     *
     * A concurrent writer can invalidate the read version between load and update; the
     * second attempt works against the state that writer left behind. A conflict that
     * survives both attempts stays a real conflict and is not swallowed.
     */
    public function failRun(string $runId): bool
    {
        foreach ([1, 2] as $attempt) {
            $run = Run::query()->find($runId);
            if (! $run instanceof Run
                || in_array($run->state, [RunState::FAILED, RunState::COMPLETED, RunState::CANCELLED], true)) {
                return false;
            }

            try {
                $this->transition($run, $run->version, RunState::FAILED, $run->phase);

                return true;
            } catch (RunTransitionConflict $conflict) {
                if ($attempt === 2) {
                    throw $conflict;
                }
            }
        }

        return false;
    }

    public function finishStep(ExecutionJob $job, string $owner, ExecutionJobState $state, string $message, ?string $failureCode = null): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_owner', $owner)->update([
                'state' => $state,
                'failure_code' => $failureCode,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, $state, $message, $state->value.':'.$job->id.':'.$job->attempts);

        return true;
    }

    public function releaseStep(ExecutionJob $job, string $owner): bool
    {
        return ExecutionJob::query()->whereKey($job->getKey())->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_owner', $owner)->update([
                'state' => ExecutionJobState::PLANNED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /** @param  array<string, scalar>  $intent */
    public function persistIntent(ExecutionJob $job, string $owner, array $intent): bool
    {
        return ExecutionJob::query()->whereKey($job->getKey())->where('state', ExecutionJobState::RUNNING->value)
            ->where('lease_owner', $owner)->update([
                'intent' => json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]) === 1;
    }

    public function recordStepEvent(string $runId, string $stepType, ExecutionJobState $state, string $message, ?string $eventKey = null): RunEvent
    {
        $run = Run::query()->findOrFail($runId);
        $redacted = $this->redactor->redact($message, new RedactionContext((string) $run->project_id, $runId, 'run-timeline'));

        return RunEvent::query()->firstOrCreate([
            'event_key' => $eventKey ?? hash('sha256', implode(':', [$runId, $stepType, $state->value, $message])),
        ], [
            'run_id' => $runId,
            'event_type' => 'step.'.$stepType.'.'.$state->value,
            'redacted_payload' => $redacted->text,
        ]);
    }

    public function finalizeClaim(
        TicketApproval $approval,
        ControlOperation $operation,
        int $attemptToken,
        string $claimParentControlSha,
        string $confirmedCommitSha,
    ): Run {
        return DB::transaction(function () use ($approval, $operation, $attemptToken, $claimParentControlSha, $confirmedCommitSha): Run {
            $approvalId = $approval->getKey();
            DB::table('ticket_approvals')->where('id', $approvalId)->lockForUpdate()->first();
            $approval = TicketApproval::query()->whereKey($approvalId)->firstOrFail();
            DB::table('projects')->where('id', $approval->project_id)->lockForUpdate()->first();
            $project = Project::query()->whereKey($approval->project_id)->firstOrFail();
            $operation->refresh();
            $existing = Run::query()->where('ticket_approval_id', $approval->getKey())->first();
            if ($existing instanceof Run) {
                if (! hash_equals($existing->initial_run_base_sha, $confirmedCommitSha)) {
                    throw new RunTransitionConflict('approval_lineage_conflict', 'The approval is already bound to another run lineage.');
                }
                if ($project->active_run_id !== $existing->getKey()
                    || $project->operation_lock_operation_id !== null) {
                    throw new RunTransitionConflict('claim_lease_not_released', 'The existing run lineage has not replaced its operation lease cleanly.');
                }

                return $existing;
            }
            if ($project->active_run_id !== null) {
                throw new RunTransitionConflict('active_run_exists', 'The project already has an active run.');
            }
            if ($operation->project_id !== $project->getKey()
                || $operation->phase !== ControlOperationPhase::CONTROL_CONFIRMED
                || $operation->state !== ControlOperationState::RUNNING
                || $operation->current_attempt_token !== $attemptToken
                || ! hash_equals((string) $operation->expected_control_commit, $claimParentControlSha)
                || ! hash_equals((string) $operation->target_control_oid, $confirmedCommitSha)) {
                throw new RunTransitionConflict('claim_operation_binding_changed', 'The run-start operation no longer owns the confirmed claim.');
            }
            if ($approval->saga_phase !== 'complete' || $approval->queue_state !== 'queued') {
                throw new RunTransitionConflict('approval_not_startable', 'The approval is not in the startable queue state.');
            }
            foreach ([$claimParentControlSha, $confirmedCommitSha] as $sha) {
                if (preg_match('/\A[0-9a-f]{64}\z/D', $sha) !== 1) {
                    throw new RunTransitionConflict('invalid_control_sha', 'The bound control commit is invalid.');
                }
            }

            $run = Run::query()->create([
                'id' => (string) Str::uuid(),
                'project_id' => $project->getKey(),
                'ticket_approval_id' => $approval->getKey(),
                'status_operation_id' => $operation->id,
                'run_type' => $approval->run_type,
                'review_subject_reference' => $approval->review_subject_reference,
                'completion_mode' => $approval->completion_mode,
                'state' => RunState::QUEUED,
                'phase' => RunPhase::PREPARE,
                'claim_parent_control_sha' => $claimParentControlSha,
                'initial_run_base_sha' => $confirmedCommitSha,
                'run_base_sha' => $confirmedCommitSha,
                'config_snapshot' => $approval->config_snapshot,
                'config_hash' => $approval->config_hash,
                'scope_snapshot' => $approval->scope_snapshot,
                'scope_hash' => $approval->scope_hash,
                'prompt_snapshot' => $approval->prompt_snapshot,
                'prompt_hash' => $approval->prompt_hash,
                'instruction_snapshot' => $approval->instruction_snapshot,
                'instruction_hash' => $approval->instruction_hash,
                'runtime_profile_snapshot' => $approval->runtime_profile_snapshot,
                'runtime_profile_hash' => $approval->runtime_profile_hash,
                'agent_profile_snapshot' => $approval->agent_profile_snapshot,
                'agent_profile_hash' => $approval->agent_profile_hash,
                'security_policy_hash' => $approval->security_policy_hash,
                'version' => 1,
            ]);
            $locked = Project::query()
                ->whereKey($project->getKey())
                ->whereNull('active_run_id')
                ->where('operation_lock_operation_id', $operation->id)
                ->where('operation_lock_attempt_token', $attemptToken)
                ->whereNull('pending_control_ref')
                ->whereNull('pending_control_oid')
                ->whereNull('pending_control_operation_id')
                ->where('control_generation', $approval->control_generation)
                ->where('control_oid', $confirmedCommitSha)
                ->update([
                    'active_run_id' => $run->id,
                    'operation_lock_operation_id' => null,
                    'operation_lock_lease_expires_at' => null,
                    'operation_lock_heartbeat_at' => null,
                ]);
            if ($locked !== 1) {
                throw new RunTransitionConflict('active_run_claim_conflict', 'The project run lock changed during finalization.');
            }
            TicketApproval::query()->whereKey($approval->getKey())->where('queue_state', 'queued')->update([
                'queue_state' => 'consumed', 'version' => DB::raw('version + 1'), 'updated_at' => now(),
            ]);
            RunEvent::query()->create(['run_id' => $run->id, 'event_type' => 'claim_finalized', 'redacted_payload' => 'Der Run-Claim wurde bestätigt.']);
            // AI6-040 owns review-source normalization and starts the first
            // review-only step. Claiming here must not create an implementation
            // workspace, branch or provider turn for that run type.
            if ($run->run_type === RunType::IMPLEMENTATION) {
                $this->planNextStep($run);
            }

            return $run;
        });
    }

    public function bindWorkspace(Run $run, int $expectedVersion, string $branch, string $worktreePath): Run
    {
        try {
            new RunBranchName($branch);
        } catch (\InvalidArgumentException) {
            throw new RunTransitionConflict('invalid_workspace_binding', 'The run workspace binding is invalid.');
        }
        if ($worktreePath === '' || str_contains($worktreePath, "\0")) {
            throw new RunTransitionConflict('invalid_workspace_binding', 'The run workspace binding is invalid.');
        }

        $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)
            ->whereNull('run_branch')->whereNull('worktree_path')->update([
                'run_branch' => $branch,
                'worktree_path' => $worktreePath,
                'version' => $expectedVersion + 1,
            ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before its workspace could be bound.');
        }

        return Run::query()->findOrFail($run->getKey());
    }

    public function bindCheckpoint(
        Run $run,
        int $expectedVersion,
        string $commitSha,
        string $treeSha,
        string $diffHash,
    ): Run {
        foreach ([$commitSha, $treeSha, $diffHash] as $value) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                throw new RunTransitionConflict('invalid_checkpoint_binding', 'The checkpoint binding is invalid.');
            }
        }

        return DB::transaction(function () use ($run, $expectedVersion, $commitSha, $treeSha, $diffHash): Run {
            DB::table('runs')->where('id', $run->getKey())->lockForUpdate()->first();
            $fresh = Run::query()->findOrFail($run->getKey());
            if ($fresh->version !== $expectedVersion) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before its checkpoint could be bound.');
            }
            if ($fresh->checkpoint_commit_sha === $commitSha && $fresh->checkpoint_tree_sha === $treeSha
                && $fresh->checkpoint_diff_hash === $diffHash && $this->hasEffectiveCheckpoint($fresh)) {
                return $fresh;
            }
            if (RunCheckpoint::query()->where('run_id', $fresh->getKey())->where('commit_sha', $commitSha)->exists()) {
                throw new RunTransitionConflict('checkpoint_rollback', 'A superseded checkpoint cannot become current again.');
            }

            $generation = ((int) RunCheckpoint::query()->where('run_id', $fresh->getKey())->max('generation')) + 1;
            RunCheckpoint::query()->where('run_id', $fresh->getKey())->where('is_current', true)->update(['is_current' => false]);
            RunCheckpoint::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $fresh->id,
                'generation' => $generation,
                'predecessor_commit_sha' => $fresh->checkpoint_commit_sha,
                'commit_sha' => $commitSha,
                'tree_sha' => $treeSha,
                'diff_hash' => $diffHash,
                'evidence_epoch' => $fresh->evidence_epoch,
                'is_current' => true,
            ]);

            $updated = Run::query()->whereKey($fresh->getKey())->where('version', $expectedVersion)->update([
                'checkpoint_commit_sha' => $commitSha,
                'checkpoint_tree_sha' => $treeSha,
                'checkpoint_diff_hash' => $diffHash,
                'checkpoint_evidence_epoch' => $fresh->evidence_epoch,
                'review_readiness_state' => null,
                'review_blockers' => null,
                'review_readiness_assessed_at' => null,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before its checkpoint could be bound.');
            }
            if ($fresh->checkpoint_commit_sha !== null && ! hash_equals($fresh->checkpoint_commit_sha, $commitSha)) {
                $this->invalidateGateEvidence($fresh->id, 'checkpoint_changed:'.$commitSha);
            }

            return Run::query()->findOrFail($fresh->getKey());
        });
    }

    public function transition(
        Run $run,
        int $expectedVersion,
        RunState $state,
        RunPhase $phase,
        ?WaitReason $waitReason = null,
        ?ControlOperation $confirmedStatusOperation = null,
    ): Run {
        if ($run->version !== $expectedVersion) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the requested transition could be applied.');
        }
        if ($run->state === $state && $run->phase === $phase && $run->wait_reason === $waitReason) {
            return $run;
        }
        $this->transitions->assertState($run->state, $state);
        $this->transitions->assertWait($state, $waitReason);
        $terminal = in_array($state, [RunState::COMPLETED, RunState::CANCELLED], true);
        // The run's own pending binding — written only through the authorized
        // bindCancellationOperation compare-and-swap — is the strongest proof
        // that this confirmed saga belongs to this run. It stays valid after
        // external base drift, where the refreshed decision legitimately
        // published on a parent that differs from the run's stale base.
        $ownBoundSaga = $confirmedStatusOperation instanceof ControlOperation
            && $run->pending_status_operation_id !== null
            && $confirmedStatusOperation->id === $run->pending_status_operation_id;
        if ($terminal && (! $confirmedStatusOperation instanceof ControlOperation
            || $confirmedStatusOperation->operation_type !== ControlOperationType::TICKET_STATUS_CHANGE
            || $confirmedStatusOperation->state !== ControlOperationState::COMPLETED
            || $confirmedStatusOperation->project_id !== $run->project_id
            || $confirmedStatusOperation->target_control_oid === null
            || (! $ownBoundSaga
                && ! hash_equals((string) $confirmedStatusOperation->expected_control_commit, $run->run_base_sha)))) {
            throw new RunTransitionConflict('terminal_status_not_confirmed', 'The project lock cannot be released before its terminal status saga is confirmed.');
        }
        if ($terminal) {
            $approvalPath = TicketApproval::query()
                ->whereKey($run->ticket_approval_id)
                ->value('relative_path');
            $terminalMutation = $confirmedStatusOperation->ticketMutation()->first();
            if (! is_string($approvalPath)
                || ! $terminalMutation instanceof TicketMutation
                || ! hash_equals($approvalPath, $terminalMutation->relative_path)
                || $terminalMutation->source_status !== 'in_progress'
                || $terminalMutation->prepared_commit_oid === null
                || ! hash_equals($terminalMutation->prepared_commit_oid, (string) $confirmedStatusOperation->target_control_oid)) {
                throw new RunTransitionConflict('terminal_status_not_run_bound', 'The confirmed terminal status operation does not belong to this run ticket.');
            }
            $this->transitions->assertTerminalTicketStatus($state, $terminalMutation->target_status);
            $parameters = json_decode($confirmedStatusOperation->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR);
            $statusOperation = is_array($parameters) ? ($parameters['status_operation'] ?? null) : null;
            if ($state === RunState::COMPLETED && $terminalMutation->target_status === 'ready'
                && ($run->run_type !== RunType::REVIEW_ONLY
                    || $statusOperation !== TicketStatusOperation::COMPLETE_REPORT_ONLY->value)) {
                throw new RunTransitionConflict('report_only_terminal_binding_invalid', 'Only the bound report-only saga may complete a run on ticket status ready.');
            }
            if ($state === RunState::COMPLETED && $run->run_type === RunType::REVIEW_ONLY
                && $terminalMutation->target_status !== 'ready') {
                throw new RunTransitionConflict('review_only_terminal_target_invalid', 'A review-only run can complete only on ticket status ready.');
            }
        }
        $terminalBase = $confirmedStatusOperation?->target_control_oid;
        $updated = DB::transaction(function () use ($run, $expectedVersion, $state, $phase, $waitReason, $terminal, $terminalBase): int {
            $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)->update([
                'state' => $state,
                'phase' => $phase,
                'wait_reason' => $waitReason,
                'run_base_sha' => $terminalBase ?? $run->run_base_sha,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            if ($updated === 1 && $terminal) {
                $released = Project::query()
                    ->whereKey($run->project_id)
                    ->where('active_run_id', $run->getKey())
                    ->whereNull('operation_lock_operation_id')
                    ->whereNull('pending_control_ref')
                    ->whereNull('pending_control_oid')
                    ->whereNull('pending_control_operation_id')
                    ->where('control_oid', $terminalBase)
                    ->update(['active_run_id' => null]);
                if ($released !== 1) {
                    throw new RunTransitionConflict('terminal_status_not_confirmed', 'The project lock cannot be released before its terminal status saga is confirmed.');
                }
            }

            return $updated;
        });
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the requested transition could be applied.');
        }

        return Run::query()->findOrFail($run->getKey());
    }

    /**
     * Park a run behind git_base_changed after unexpected base drift.
     *
     * Drift is answered from every non-terminal state, not only from a running
     * run: a run that already waits behind contract_change while its amendment
     * compare-and-swap fails would otherwise keep advertising a resolver that
     * can no longer succeed, and a queued run would keep no record at all.
     * The call is idempotent — a run already parked behind git_base_changed is
     * returned unchanged — and it never releases the project lock (AC-13).
     */
    public function parkOnBaseDrift(Run $run, int $expectedVersion): Run
    {
        if ($run->version !== $expectedVersion) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the base drift could be recorded.');
        }
        if ($run->state === RunState::WAITING && $run->wait_reason === WaitReason::GIT_BASE_CHANGED) {
            return $run;
        }
        $this->transitions->assertBaseDriftPark($run->state);

        $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)->update([
            'state' => RunState::WAITING,
            'wait_reason' => WaitReason::GIT_BASE_CHANGED,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the base drift could be recorded.');
        }

        return Run::query()->findOrFail($run->getKey());
    }

    /** Park a status-saga compare-and-swap conflict without releasing its run lock. */
    public function parkOnGitConflict(Run $run, int $expectedVersion): Run
    {
        if ($run->version !== $expectedVersion) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the Git conflict could be recorded.');
        }
        if ($run->state === RunState::WAITING && $run->wait_reason === WaitReason::GIT_CONFLICT) {
            return $run;
        }
        if (in_array($run->state, [RunState::COMPLETED, RunState::CANCELLED], true)) {
            throw new RunTransitionConflict('run_terminal', 'A terminal run cannot enter a Git conflict wait.');
        }

        $updated = Run::query()->whereKey($run->id)->where('version', $expectedVersion)->update([
            'state' => RunState::WAITING,
            'wait_reason' => WaitReason::GIT_CONFLICT,
            'version' => $expectedVersion + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the Git conflict could be recorded.');
        }

        return Run::query()->findOrFail($run->id);
    }

    public function bindCancellationOperation(Run $run, int $expectedVersion, ControlOperation $operation): Run
    {
        if ($operation->operation_type !== ControlOperationType::TICKET_STATUS_CHANGE
            || $operation->project_id !== $run->project_id) {
            throw new RunTransitionConflict('cancellation_operation_invalid', 'The cancellation operation does not match the run.');
        }
        $updated = Run::query()->whereKey($run->id)->where('version', $expectedVersion)
            ->whereNull('pending_status_operation_id')->update([
                'pending_status_operation_id' => $operation->id,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the cancellation saga was bound.');
        }

        return Run::query()->findOrFail($run->id);
    }

    public function bindReportOnlyCompletionOperation(Run $run, int $expectedVersion, ControlOperation $operation): Run
    {
        $this->transitions->assertReportStatusSync($run->state, $run->wait_reason);
        if ($run->run_type !== RunType::REVIEW_ONLY
            || $operation->operation_type !== ControlOperationType::TICKET_STATUS_CHANGE
            || $operation->project_id !== $run->project_id
            || ! in_array($run->state, [RunState::RUNNING, RunState::WAITING], true)) {
            throw new RunTransitionConflict('report_completion_operation_invalid', 'The report-only completion operation does not match the run.');
        }
        $updated = Run::query()->whereKey($run->id)->where('version', $expectedVersion)
            ->whereNull('pending_status_operation_id')->update([
                'pending_status_operation_id' => $operation->id,
                'state' => RunState::WAITING,
                'wait_reason' => WaitReason::STATUS_SYNC,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the report completion saga was bound.');
        }

        return Run::query()->findOrFail($run->id);
    }

    /**
     * Supersede a conflicted cancellation binding so a re-authorized decision
     * can bind a fresh status operation (AC-14). The conflicted operation and
     * its intervention stay readable as evidence; only the run's pending
     * binding is released.
     */
    public function releaseCancellationOperation(Run $run, ControlOperation $operation): Run
    {
        Run::query()->whereKey($run->id)
            ->where('pending_status_operation_id', $operation->id)
            ->update([
                'pending_status_operation_id' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        return Run::query()->findOrFail($run->id);
    }

    /** Park a planned bound step while its run waits for a human decision. */
    public function parkBoundStep(ExecutionJob $job): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::PLANNED->value)->update([
                'state' => ExecutionJobState::WAITING,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            $fresh = ExecutionJob::query()->find($job->getKey());
            if ($fresh instanceof ExecutionJob && $fresh->state === ExecutionJobState::WAITING
                && $fresh->lease_owner === null) {
                return true;
            }

            return is_string($job->lease_owner) && $job->lease_owner !== ''
                ? $this->parkStep($job, $job->lease_owner)
                : false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::WAITING, 'Schritt wartet auf eine Entscheidung.', 'waiting:'.$job->id.':'.$job->attempts);

        return true;
    }

    /**
     * Resolve a human-question wait and resume exactly the bound step once.
     *
     * A second call after the wait has already been cleared does not plan the
     * step again and does not emit a second resume event.
     */
    public function resumeHumanQuestion(Run $run, int $expectedVersion, string $boundStepKey): Run
    {
        return $this->resumeWait($run, $expectedVersion, $boundStepKey, WaitReason::HUMAN_QUESTION);
    }

    /** Cancel a human-question wait through the existing non-terminal abort path. */
    public function cancelHumanQuestion(Run $run, int $expectedVersion): Run
    {
        return $this->cancelWait($run, $expectedVersion, WaitReason::HUMAN_QUESTION);
    }

    public function resumeWait(Run $run, int $expectedVersion, string $boundStepKey, WaitReason $reason): Run
    {
        if ($run->state === RunState::RUNNING && $run->wait_reason === null) {
            return $run;
        }
        if ($run->state !== RunState::WAITING || $run->wait_reason !== $reason) {
            throw new RunTransitionConflict($reason->value.'_not_waiting', 'The run is not waiting for the bound '.$reason->value.' resolver.');
        }

        $resumed = $this->transition($run, $expectedVersion, RunState::RUNNING, $run->phase);
        $job = ExecutionJob::query()
            ->where('run_id', $run->getKey())
            ->where('idempotency_key', $boundStepKey)
            ->first();
        if ($job instanceof ExecutionJob && $this->resumeStep($job, $reason === WaitReason::SCOPE_APPROVAL)) {
            $this->redeliverStep($job);
        }

        return $resumed;
    }

    public function cancelWait(Run $run, int $expectedVersion, WaitReason $reason): Run
    {
        if ($run->version !== $expectedVersion) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the requested transition could be applied.');
        }
        if ($run->state !== RunState::WAITING || $run->wait_reason !== $reason) {
            throw new RunTransitionConflict($reason->value.'_not_waiting', 'The run is not waiting for the bound '.$reason->value.' resolver.');
        }
        $this->failRun($run->getKey());

        return Run::query()->findOrFail($run->getKey());
    }

    public function resumeResourceLimit(Run $run, int $expectedVersion, string $boundStepKey): Run
    {
        return $this->resumeWait($run, $expectedVersion, $boundStepKey, WaitReason::RESOURCE_LIMIT);
    }

    public function cancelResourceLimit(Run $run, int $expectedVersion): Run
    {
        return $this->cancelWait($run, $expectedVersion, WaitReason::RESOURCE_LIMIT);
    }

    /**
     * Resolve a failed check by retrying its parked step (plan §7.2).
     *
     * The step key binds the retry to exactly the step that failed, so a resume
     * cannot restart a different one; the check itself stays bound to the tree
     * it ran against through its own deterministic result key.
     */
    public function resumeCheckFailure(Run $run, int $expectedVersion, string $boundStepKey): Run
    {
        return $this->resumeWait($run, $expectedVersion, $boundStepKey, WaitReason::CHECK_FAILURE);
    }

    public function cancelCheckFailure(Run $run, int $expectedVersion): Run
    {
        return $this->cancelWait($run, $expectedVersion, WaitReason::CHECK_FAILURE);
    }

    public function ensureImplementationSlot(Run $run): RunAgent
    {
        $implementation = ($run->agent_profile_snapshot ?? [])['implementation'] ?? [];
        $slot = RunAgent::query()->where('run_id', $run->getKey())->where('role', 'implementation')->first();
        if ($slot instanceof RunAgent) {
            return $slot;
        }

        $provider = $implementation['provider_profile'] ?? null;
        $model = $implementation['model'] ?? null;
        $effort = $implementation['effort'] ?? null;
        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === '' || ! is_string($effort) || $effort === '') {
            throw new ImplementationImportException(
                'approval_slot_incomplete',
                'The implementation slot requires provider profile, model and effort from the approval snapshot.',
            );
        }

        return RunAgent::query()->create([
            'run_id' => $run->id,
            'slot_id' => (string) Str::uuid(),
            'role' => 'implementation',
            'provider_profile' => $provider,
            'model' => $model,
            'effort' => $effort,
            'prompt_profile' => 'implementation',
            'session_id' => null,
        ]);
    }

    public function bindImplementationSession(Run $run, string $slotId, string $sessionId): RunAgent
    {
        $slot = RunAgent::query()->where('run_id', $run->getKey())->where('slot_id', $slotId)->firstOrFail();
        if (is_string($slot->session_id) && $slot->session_id !== '') {
            return $slot;
        }
        $slot->forceFill(['session_id' => $sessionId])->save();

        return $slot->fresh() ?? $slot;
    }

    public function discardImplementationSessions(Run $run): void
    {
        RunAgent::query()->where('run_id', $run->getKey())->where('role', 'implementation')->update(['session_id' => null]);
    }

    /**
     * Materialize the immutable reviewer set from the run-bound approval snapshot.
     *
     * @return non-empty-list<RunAgent>
     */
    public function materializeReviewSlots(Run $run): array
    {
        $reviewers = ($run->agent_profile_snapshot ?? [])['reviewers'] ?? null;
        if (! is_array($reviewers) || ! array_is_list($reviewers) || $reviewers === []) {
            throw new ImplementationImportException('approval_reviewers_missing', 'The approval snapshot contains no reviewer slots.');
        }

        $slots = [];
        foreach ($reviewers as $reviewer) {
            if (! is_array($reviewer)) {
                throw new ImplementationImportException('approval_reviewer_incomplete', 'A reviewer slot is incomplete.');
            }
            $values = [
                'slot_id' => $reviewer['id'] ?? null,
                'provider_profile' => $reviewer['provider_profile'] ?? null,
                'model' => $reviewer['model'] ?? null,
                'effort' => $reviewer['effort'] ?? null,
                'prompt_profile' => $reviewer['prompt_profile_id'] ?? null,
            ];
            foreach ($values as $value) {
                if (! is_string($value) || $value === '') {
                    throw new ImplementationImportException('approval_reviewer_incomplete', 'A reviewer slot is incomplete.');
                }
            }

            $slot = RunAgent::query()->where('run_id', $run->id)
                ->where('role', 'quality_review')->where('is_active', true)
                ->where('slot_id', $values['slot_id'])->first();
            $slot ??= RunAgent::query()->where('run_id', $run->id)
                ->where('role', 'quality_review')->where('is_active', true)
                ->where('approval_slot_id', $values['slot_id'])->orderByDesc('slot_revision')->first();
            if (! $slot instanceof RunAgent) {
                $slot = RunAgent::query()->create([
                    'run_id' => $run->id,
                    'slot_id' => $values['slot_id'],
                    'approval_slot_id' => $values['slot_id'],
                    'slot_revision' => 1,
                    'is_active' => true,
                    'role' => 'quality_review',
                    'provider_profile' => $values['provider_profile'],
                    'model' => $values['model'],
                    'effort' => $values['effort'],
                    'prompt_profile' => $values['prompt_profile'],
                    'session_id' => null,
                ]);
            }
            if ($slot->role !== 'quality_review') {
                throw new ImplementationImportException('approval_reviewer_mismatch', 'A materialized reviewer no longer matches the approval snapshot.');
            }
            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * Create a new immutable reviewer-slot revision and retire only the old
     * active slot. Existing review results keep their original slot binding.
     */
    public function reviseReviewSlot(
        Run $run,
        string $slotId,
        string $providerProfile,
        string $model,
        string $effort,
        string $promptProfile,
    ): RunAgent {
        foreach ([$providerProfile, $model, $effort, $promptProfile] as $value) {
            if ($value === '') {
                throw new RunTransitionConflict('reviewer_revision_invalid', 'The reviewer revision is incomplete.');
            }
        }
        $approved = false;
        foreach (($run->agent_profile_snapshot ?? [])['reviewers'] ?? [] as $reviewer) {
            if (is_array($reviewer)
                && ($reviewer['provider_profile'] ?? null) === $providerProfile
                && ($reviewer['model'] ?? null) === $model
                && ($reviewer['effort'] ?? null) === $effort
                && ($reviewer['prompt_profile_id'] ?? null) === $promptProfile) {
                $approved = true;
                break;
            }
        }
        if (! $approved) {
            throw new RunTransitionConflict('reviewer_revision_not_approved', 'The reviewer revision is absent from the run-bound approval snapshot.');
        }

        return DB::transaction(function () use ($run, $slotId, $providerProfile, $model, $effort, $promptProfile): RunAgent {
            DB::table('runs')->where('id', $run->id)->lockForUpdate()->first();
            $old = RunAgent::query()->where('run_id', $run->id)->where('slot_id', $slotId)
                ->where('role', 'quality_review')->where('is_active', true)->first();
            if (! $old instanceof RunAgent) {
                throw new RunTransitionConflict('reviewer_slot_not_active', 'The reviewer slot is no longer active.');
            }
            $approvalSlotId = $old->approval_slot_id ?? $old->slot_id;
            $old->forceFill(['is_active' => false])->save();

            return RunAgent::query()->create([
                'run_id' => $run->id,
                'slot_id' => (string) Str::uuid(),
                'approval_slot_id' => $approvalSlotId,
                'slot_revision' => $old->slot_revision + 1,
                'is_active' => true,
                'role' => 'quality_review',
                'provider_profile' => $providerProfile,
                'model' => $model,
                'effort' => $effort,
                'prompt_profile' => $promptProfile,
                'session_id' => (string) Str::uuid(),
            ]);
        });
    }

    public function bindReviewSession(Run $run, string $slotId, string $sessionId): RunAgent
    {
        $slot = RunAgent::query()->where('run_id', $run->id)->where('slot_id', $slotId)
            ->where('role', 'quality_review')->firstOrFail();
        if (is_string($slot->session_id) && $slot->session_id !== '') {
            return $slot;
        }
        $slot->forceFill(['session_id' => $sessionId])->save();

        return $slot->fresh() ?? $slot;
    }

    public function discardReviewSession(Run $run, string $slotId): void
    {
        RunAgent::query()->where('run_id', $run->id)->where('slot_id', $slotId)
            ->where('role', 'quality_review')->update(['session_id' => null]);
    }

    public function resumeScopeApproval(Run $run, int $expectedVersion, string $boundStepKey): Run
    {
        return $this->resumeWait($run, $expectedVersion, $boundStepKey, WaitReason::SCOPE_APPROVAL);
    }

    public function cancelScopeApproval(Run $run, int $expectedVersion): Run
    {
        return $this->cancelWait($run, $expectedVersion, WaitReason::SCOPE_APPROVAL);
    }

    /**
     * Idempotently record one exact-path scope decision and, if approved within
     * the freigegebene max_added_scope_paths, extend the run's bound effective
     * scope (TKT-007).
     *
     * A path already decided for this run returns the run unchanged: retry,
     * partial approval and a later contract amendment all consume the same
     * idempotent counter through this one seam (AC-05). Approving beyond the
     * limit records nothing and throws {@see ScopePathLimitExceeded} so the
     * caller can route the run into the resource_limit wait instead (AC-04).
     */
    public function applyScopeDecision(
        Run $run,
        string $path,
        bool $approved,
        ?string $humanRequestId,
        int $maxAddedScopePaths,
        CanonicalJson $canonicalJson,
        string $reason,
        ?int $expectedVersion = null,
    ): Run {
        return DB::transaction(function () use ($run, $path, $approved, $humanRequestId, $maxAddedScopePaths, $canonicalJson, $reason, $expectedVersion): Run {
            DB::table('runs')->where('id', $run->getKey())->lockForUpdate()->first();
            $fresh = Run::query()->findOrFail($run->getKey());

            // A human scope decision carries the run version its request was
            // bound to. Comparing the caller's value against the stored binding
            // alone cannot falsify drift — both are the same stale number — so
            // the decision itself runs compare-and-swap against the run: if the
            // run moved after the request was opened (for example through a
            // contract amendment), the decision is refused and stays without
            // effect (AC-14, HUM-004).
            if ($expectedVersion !== null && $fresh->version !== $expectedVersion) {
                throw new RunTransitionConflict('stale_run_version', 'The run moved after the scope request was opened.');
            }

            $existing = ScopeDecision::query()->where('run_id', $fresh->getKey())->where('path', $path)->first();
            if ($existing instanceof ScopeDecision) {
                return $fresh;
            }

            if ($approved && $fresh->added_scope_paths_count >= $maxAddedScopePaths) {
                throw new ScopePathLimitExceeded($fresh->added_scope_paths_count + 1, $maxAddedScopePaths);
            }

            ScopeDecision::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $fresh->getKey(),
                'path' => $path,
                'outcome' => $approved ? 'approved' : 'rejected',
                'reason' => $reason,
                'human_request_id' => $humanRequestId,
            ]);

            if (! $approved) {
                return $fresh;
            }

            /** @var list<string> $initialScope */
            $initialScope = $fresh->scope_snapshot['ticket_files'] ?? [];
            /** @var list<string> $approvedAdditions */
            $approvedAdditions = ScopeDecision::query()
                ->where('run_id', $fresh->getKey())
                ->where('outcome', 'approved')
                ->pluck('path')
                ->all();
            $effective = EffectiveScope::compute($initialScope, $approvedAdditions, $canonicalJson);

            // An approved addition changes the effective scope, so every piece of
            // evidence bound to the previous scope loses its effectiveness (AC-10).
            // The originals stay untouched; only the epoch moves.
            $updated = Run::query()->whereKey($fresh->getKey())->where('version', $fresh->version)->update([
                'effective_scope_snapshot' => $effective->effectiveScope,
                'effective_scope_hash' => $effective->hash,
                'added_scope_paths_count' => DB::raw('added_scope_paths_count + 1'),
                'evidence_epoch' => DB::raw('evidence_epoch + 1'),
                'review_readiness_state' => null,
                'review_blockers' => null,
                'review_readiness_assessed_at' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before the scope decision could be bound.');
            }
            $this->recordEvidenceInvalidation($fresh->getKey(), 'scope_extension:'.$path);
            RunGate::query()->where('run_id', $fresh->getKey())->where('state', GateState::CLOSED->value)->update([
                'state' => GateState::OPEN, 'invalidated_at' => now(), 'updated_at' => now(),
            ]);

            return Run::query()->findOrFail($fresh->getKey());
        });
    }

    /**
     * Whether the bound checkpoint is still effective evidence.
     *
     * A checkpoint stays readable forever; it is effective only while no scope
     * or contract change moved the run's evidence epoch past the one the
     * checkpoint was bound under (AC-10).
     */
    public function hasEffectiveCheckpoint(Run $run): bool
    {
        return $run->checkpoint_commit_sha !== null
            && $run->checkpoint_evidence_epoch === $run->evidence_epoch;
    }

    /**
     * Bind the actually changed paths of the current import to the run.
     *
     * @param  list<string>  $paths
     */
    public function bindActualChangedPaths(Run $run, int $expectedVersion, array $paths, CanonicalJson $canonicalJson): Run
    {
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        $hash = hash('sha256', "AI6-ACTUAL-CHANGED-PATHS-V1\0".$canonicalJson->normalizeAndEncode(['paths' => $paths]));

        $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)->update([
            'actual_changed_paths_snapshot' => $paths,
            'actual_changed_paths_hash' => $hash,
            'evidence_epoch' => $run->checkpoint_commit_sha === null ? $run->evidence_epoch : DB::raw('evidence_epoch + 1'),
            'review_readiness_state' => null,
            'review_blockers' => null,
            'review_readiness_assessed_at' => null,
            'version' => $expectedVersion + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before the actual paths could be bound.');
        }

        if ($run->checkpoint_commit_sha !== null) {
            $this->recordEvidenceInvalidation($run->getKey(), 'implementation_diff:'.$hash);
            RunGate::query()->where('run_id', $run->getKey())->where('state', GateState::CLOSED->value)->update([
                'state' => GateState::OPEN, 'invalidated_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Run::query()->findOrFail($run->getKey());
    }

    /**
     * Bind a confirmed contract amendment to its run (AC-07, AC-08, AC-10).
     *
     * Only run_base_sha moves forward; initial_run_base_sha stays immutable
     * behind its trigger. The new ticket blob, contract, scope, config and
     * prompt bindings replace the previous ones, the effective scope is
     * recomputed over the amended initial scope plus the already approved
     * additions, and the evidence epoch invalidates dependent evidence.
     *
     * @param  array<string, mixed>  $scopeSnapshot
     * @param  array<string, mixed>  $configSnapshot
     * @param  array<string, mixed>  $promptSnapshot
     */
    public function applyContractAmendment(
        Run $run,
        string $newRunBaseSha,
        string $ticketBlobSha,
        string $ticketContractSha256,
        array $scopeSnapshot,
        string $scopeHash,
        array $configSnapshot,
        string $configHash,
        array $promptSnapshot,
        string $promptHash,
        CanonicalJson $canonicalJson,
        int $maxAddedScopePaths,
        ?string $humanRequestId = null,
    ): Run {
        foreach ([$newRunBaseSha, $ticketBlobSha, $ticketContractSha256, $scopeHash, $configHash, $promptHash] as $value) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $value) !== 1) {
                throw new RunTransitionConflict('invalid_amendment_binding', 'The contract amendment binding is invalid.');
            }
        }

        return DB::transaction(function () use ($run, $newRunBaseSha, $ticketBlobSha, $ticketContractSha256, $scopeSnapshot, $scopeHash, $configSnapshot, $configHash, $promptSnapshot, $promptHash, $canonicalJson, $maxAddedScopePaths, $humanRequestId): Run {
            DB::table('runs')->where('id', $run->getKey())->lockForUpdate()->first();
            $fresh = Run::query()->findOrFail($run->getKey());
            if (in_array($fresh->state, [RunState::COMPLETED, RunState::CANCELLED], true)) {
                throw new RunTransitionConflict('run_terminal', 'A terminal run cannot receive a contract amendment.');
            }

            /** @var list<string> $initialScope */
            $initialScope = array_values(array_filter($scopeSnapshot['ticket_files'] ?? [], 'is_string'));
            /** @var list<string> $previousInitialScope */
            $previousInitialScope = array_values(array_filter(($fresh->scope_snapshot ?? [])['ticket_files'] ?? [], 'is_string'));

            // A path the amendment adds to the ticket's files list joins the
            // effective scope exactly like an approved addition and therefore
            // consumes the same idempotent counter against the freigegebene
            // max_added_scope_paths (AC-05, plan §8.2). Recording it as a
            // ScopeDecision is what makes the consumption idempotent: a replay
            // of the same amendment finds the row and does not count again.
            $consumed = 0;
            foreach (array_values(array_diff($initialScope, $previousInitialScope)) as $addedPath) {
                $existing = ScopeDecision::query()->where('run_id', $fresh->getKey())->where('path', $addedPath)->first();
                if ($existing instanceof ScopeDecision) {
                    // An already approved path consumed the counter once and
                    // never again. A rejected one must never reappear here:
                    // both amendment guards refuse that re-add before any
                    // commit exists, so reaching this point means the state
                    // moved underneath the saga.
                    if ($existing->outcome === 'rejected') {
                        throw new RunTransitionConflict(
                            'amendment_readds_rejected_path',
                            'A rejected additional path cannot re-enter the effective scope through an amendment.',
                        );
                    }

                    continue;
                }
                if ($fresh->added_scope_paths_count + $consumed + 1 > $maxAddedScopePaths) {
                    throw new ScopePathLimitExceeded($fresh->added_scope_paths_count + $consumed + 1, $maxAddedScopePaths);
                }
                ScopeDecision::query()->create([
                    'id' => (string) Str::uuid(),
                    'run_id' => $fresh->getKey(),
                    'path' => $addedPath,
                    'outcome' => 'approved',
                    'reason' => 'amendment',
                    'human_request_id' => $humanRequestId,
                ]);
                $consumed++;
            }

            /** @var list<string> $approvedAdditions */
            $approvedAdditions = ScopeDecision::query()
                ->where('run_id', $fresh->getKey())
                ->where('outcome', 'approved')
                ->pluck('path')
                ->all();
            $effective = EffectiveScope::compute($initialScope, $approvedAdditions, $canonicalJson);
            $hasEffectiveState = $approvedAdditions !== [] || $fresh->effective_scope_snapshot !== null;

            $updated = Run::query()->whereKey($fresh->getKey())->where('version', $fresh->version)->update([
                'added_scope_paths_count' => $fresh->added_scope_paths_count + $consumed,
                'run_base_sha' => $newRunBaseSha,
                'ticket_blob_sha' => $ticketBlobSha,
                'ticket_contract_sha256' => $ticketContractSha256,
                'scope_snapshot' => $scopeSnapshot,
                'scope_hash' => $scopeHash,
                'config_snapshot' => $configSnapshot,
                'config_hash' => $configHash,
                'prompt_snapshot' => $promptSnapshot,
                'prompt_hash' => $promptHash,
                'effective_scope_snapshot' => $hasEffectiveState ? $effective->effectiveScope : null,
                'effective_scope_hash' => $hasEffectiveState ? $effective->hash : null,
                'evidence_epoch' => DB::raw('evidence_epoch + 1'),
                'review_readiness_state' => null,
                'review_blockers' => null,
                'review_readiness_assessed_at' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new RunTransitionConflict('stale_run_version', 'The run changed before the contract amendment could be bound.');
            }
            $this->recordEvidenceInvalidation($fresh->getKey(), 'contract_amendment:'.$newRunBaseSha);
            RunGate::query()->where('run_id', $fresh->getKey())->where('state', GateState::CLOSED->value)->update([
                'state' => GateState::OPEN, 'invalidated_at' => now(), 'updated_at' => now(),
            ]);

            return Run::query()->findOrFail($fresh->getKey());
        });
    }

    /** Persist every gate declared by the approved ticket exactly once. */
    public function prepareGates(Run $run, TicketDocument $ticket, ?string $observedContract = null): void
    {
        $contract = $run->ticket_contract_sha256
            ?? TicketApproval::query()->whereKey($run->ticket_approval_id)->value('ticket_contract_sha256');
        if (! is_string($contract) || preg_match('/\A[0-9a-f]{64}\z/D', $contract) !== 1) {
            throw new RunTransitionConflict('gate_contract_missing', 'The approved ticket contract is not bound.');
        }
        if ($observedContract !== null && ! hash_equals($contract, $observedContract)) {
            throw new RunTransitionConflict('ticket_contract_drift', 'The current ticket contract differs from the approved contract.');
        }

        foreach ([GateKind::MANUAL->value => $ticket->manualGateIds, GateKind::EXTERNAL->value => $ticket->externalGateIds] as $kind => $ids) {
            foreach ($ids as $gateId) {
                $gate = RunGate::query()->firstOrCreate(['run_id' => $run->id, 'gate_id' => $gateId], [
                    'id' => (string) Str::uuid(),
                    'kind' => $kind,
                    'state' => GateState::OPEN,
                    'ticket_contract_sha256' => $contract,
                ]);
                if (! hash_equals($gate->ticket_contract_sha256, $contract)) {
                    $gate->forceFill([
                        'state' => GateState::OPEN,
                        'ticket_contract_sha256' => $contract,
                        'invalidated_at' => now(),
                    ])->save();
                }
            }
        }
    }

    public function authorizeGateEvidence(Run $run, string $gateId, int $actorId, string $reference): RunGate
    {
        $actor = User::query()->find($actorId);
        $project = Project::query()->find($run->project_id);
        if (! $actor instanceof User || ! $project instanceof Project
            || ! $this->projectPolicy->decide(ProjectAction::AUTHORIZE_GATE_EVIDENCE, $actor, $project)) {
            throw new RunTransitionConflict('gate_evidence_unauthorized', 'The actor may not authorize gate evidence for this project.');
        }
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@+-]{0,511}\z/D', $reference) !== 1
            || preg_match('/\A(?:MG|EXT)-[0-9]{2}\z/D', $gateId) !== 1
            || ! $this->hasEffectiveCheckpoint($run) || $run->checkpoint_commit_sha === null) {
            throw new RunTransitionConflict('gate_evidence_binding_invalid', 'Gate evidence requires the current ticket contract and checkpoint.');
        }
        $gate = RunGate::query()->where('run_id', $run->id)->where('gate_id', $gateId)->firstOrFail();
        $gate->forceFill([
            'state' => GateState::CLOSED,
            'evidence_reference' => $reference,
            'evidence_ticket_contract_sha256' => $gate->ticket_contract_sha256,
            'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'authorized_by' => $actorId,
            'authorized_at' => now(),
            'invalidated_at' => null,
        ])->save();

        return $gate->fresh() ?? $gate;
    }

    /** The one application-wide review-readiness decision. */
    public function reviewReadiness(Run $run, string $observedDiffHash, string $observedTreeBinding): ReviewReadinessDecision
    {
        $blockers = [];
        $scope = $run->effective_scope_snapshot
            ?? array_values(array_filter(($run->scope_snapshot ?? [])['ticket_files'] ?? [], 'is_string'));
        foreach ($this->scopeReconciliation->unresolved($run->actual_changed_paths_snapshot ?? [], $scope) as $path) {
            $blockers[] = new ReviewBlocker('scope_unresolved', 'Geänderter Pfad ist im wirksamen Scope ungeklärt: '.$path);
        }

        $profiles = ($run->config_snapshot ?? [])['values']['checks'][CheckPhase::BEFORE_REVIEW->value] ?? null;
        if (! is_array($profiles) || ! array_is_list($profiles)) {
            $blockers[] = new ReviewBlocker('check_snapshot_invalid', 'Der gebundene Konfigurations-Snapshot enthält keine gültige before_review-Checkliste.');
        } else {
            foreach (array_values(array_unique(array_filter($profiles, 'is_string'))) as $profile) {
                $result = CheckResultRecord::query()->where('run_id', $run->id)
                    ->where('phase', CheckPhase::BEFORE_REVIEW->value)->where('profile', $profile)
                    ->where('evidence_epoch', $run->evidence_epoch)
                    ->whereNull('superseded_at')->orderByDesc('created_at')->get()->first();
                if ($result === null) {
                    $blockers[] = new ReviewBlocker('required_check_missing', 'Pflichtcheck wurde für den aktuellen Stand nicht ausgeführt: '.$profile);
                } elseif ($result->state !== CheckResultState::SUCCEEDED) {
                    $blockers[] = new ReviewBlocker('required_check_'.$result->state->value, 'Pflichtcheck ist nicht bestanden: '.$profile.' ('.$result->state->value.').');
                } elseif (! hash_equals($result->tree_sha, $observedTreeBinding)) {
                    $blockers[] = new ReviewBlocker('required_check_tree_mismatch', 'Pflichtcheck gehört nicht zum finalen Checkpoint-Baum: '.$profile.'.');
                }
            }
        }

        $openGates = RunGate::query()->where('run_id', $run->id)->where('state', GateState::OPEN->value)
            ->orderBy('gate_id')->pluck('gate_id')->map(static fn (mixed $id): string => (string) $id)->all();

        $approval = TicketApproval::query()->find($run->ticket_approval_id);
        $project = Project::query()->find($run->project_id);
        $readModel = $approval instanceof TicketApproval ? TicketReadModel::query()
            ->where('project_id', $run->project_id)->where('relative_path', $approval->relative_path)->first() : null;
        $contract = $run->ticket_contract_sha256 ?? $approval?->ticket_contract_sha256;
        if (! is_string($contract) || ! $readModel instanceof TicketReadModel
            || ! is_string($readModel->ticket_contract_sha256) || ! hash_equals($contract, $readModel->ticket_contract_sha256)) {
            $blockers[] = new ReviewBlocker('ticket_contract_drift', 'Der freigegebene Ticketvertrag hat sich geändert.');
        }
        if (! $project instanceof Project || ! is_string($project->control_oid) || ! hash_equals($run->run_base_sha, $project->control_oid)) {
            $blockers[] = new ReviewBlocker('control_head_drift', 'Der Control-Branch-Head oder die Runbasis hat sich geändert.');
        }

        if (! $this->hasEffectiveCheckpoint($run) || $run->checkpoint_diff_hash === null) {
            $blockers[] = new ReviewBlocker('checkpoint_missing', 'Für den aktuellen Stand fehlt ein gebundener Checkpoint.');
        } elseif (! hash_equals($run->checkpoint_diff_hash, $observedDiffHash)) {
            $blockers[] = new ReviewBlocker('checkpoint_diff_mismatch', 'Der tatsächliche Diff stimmt nicht mit dem Checkpoint überein.');
        }

        return new ReviewReadinessDecision($blockers, $openGates);
    }

    public function recordReviewReadiness(Run $run, ReviewReadinessDecision $decision): Run
    {
        $context = new RedactionContext((string) $run->project_id, $run->id, 'review-readiness');
        $blockers = array_map(
            fn (ReviewBlocker $blocker): array => [
                'code' => $blocker->code,
                'message' => $this->redactor->redact($blocker->message, $context)->text,
            ],
            $decision->blockers,
        );
        $updated = Run::query()->whereKey($run->getKey())->where('version', $run->version)->update([
            'review_readiness_state' => $decision->ready() ? 'ready' : 'blocked',
            'review_blockers' => $blockers,
            'review_readiness_assessed_at' => now(),
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before review readiness could be recorded.');
        }

        return Run::query()->findOrFail($run->getKey());
    }

    private function invalidateGateEvidence(string $runId, string $cause): void
    {
        RunGate::query()->where('run_id', $runId)->where('state', GateState::CLOSED->value)->update([
            'state' => GateState::OPEN,
            'invalidated_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordEvidenceInvalidation($runId, 'gate:'.$cause);
    }

    private function recordEvidenceInvalidation(string $runId, string $cause): void
    {
        RunEvent::query()->firstOrCreate([
            'event_key' => hash('sha256', implode(':', [$runId, 'evidence.invalidated', $cause])),
        ], [
            'run_id' => $runId,
            'event_type' => 'evidence.invalidated',
            'redacted_payload' => 'Abhängige Evidenz wurde durch eine Scope- oder Vertragsänderung unwirksam.',
        ]);
    }
}
