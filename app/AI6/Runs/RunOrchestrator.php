<?php

namespace App\AI6\Runs;

use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\RunBranchName;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
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
    ) {}

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

    /**
     * The single next-step decision of this application.
     *
     * @param  list<string>  $completedStepTypes
     */
    public static function decideNextStep(RunState $state, ?WaitReason $waitReason, array $completedStepTypes): ?ExecutionStepType
    {
        if (in_array($state, [RunState::FAILED, RunState::COMPLETED, RunState::CANCELLED], true)) {
            return null;
        }
        if ($state === RunState::WAITING || $waitReason instanceof WaitReason) {
            return null;
        }

        foreach (ExecutionStepType::cases() as $type) {
            if (! in_array($type->value, $completedStepTypes, true)) {
                return $type;
            }
        }

        return null;
    }

    public function nextStep(Run $run, ?ExecutionStepType $completing = null): ?ExecutionStepType
    {
        $completed = ExecutionJob::query()->where('run_id', $run->getKey())
            ->where('state', ExecutionJobState::SUCCEEDED->value)->pluck('step_type')->all();
        if ($completing instanceof ExecutionStepType) {
            $completed[] = $completing->value;
        }

        /** @var list<string> $completed */
        $completed = array_values(array_map(strval(...), $completed));

        return self::decideNextStep($run->state, $run->wait_reason, $completed);
    }

    /**
     * Plan the step the next-step decision names and hand it to the queue.
     *
     * This is the only way an execution job is created; the decision itself never
     * happens outside this class.
     */
    public function planNextStep(Run $run, ?ExecutionStepType $completing = null): ?ExecutionJob
    {
        $type = $this->nextStep($run, $completing);
        if (! $type instanceof ExecutionStepType) {
            return null;
        }

        return $this->ensureStep($run, $type);
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
    public function applyPreparedStepEffect(Run $run, ExecutionStepType $completed): bool
    {
        $fresh = Run::query()->findOrFail($run->getKey());
        if (! in_array($fresh->state, [RunState::QUEUED, RunState::RUNNING], true)) {
            return false;
        }
        if ($completed === ExecutionStepType::PREFLIGHT && $fresh->state === RunState::QUEUED) {
            $fresh = $this->transition($fresh, $fresh->version, RunState::RUNNING, RunPhase::IMPLEMENT);
        }

        $this->planNextStep($fresh, $completed);

        return true;
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

    /** Return a parked step to the planned state once its run no longer waits. */
    public function resumeStep(ExecutionJob $job): bool
    {
        $updated = ExecutionJob::query()->whereKey($job->getKey())
            ->where('state', ExecutionJobState::WAITING->value)->update([
                'state' => ExecutionJobState::PLANNED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return false;
        }

        $this->recordStepEvent($job->run_id, $job->step_type, ExecutionJobState::PLANNED, 'Schritt erneut geplant.', 'planned:'.$job->id.':'.$job->attempts);

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
                'run_type' => 'implementation',
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
            $this->planNextStep($run);

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
        $updated = Run::query()->whereKey($run->getKey())->where('version', $expectedVersion)
            ->whereNull('checkpoint_commit_sha')->whereNull('checkpoint_tree_sha')->whereNull('checkpoint_diff_hash')->update([
                'checkpoint_commit_sha' => $commitSha,
                'checkpoint_tree_sha' => $treeSha,
                'checkpoint_diff_hash' => $diffHash,
                'version' => $expectedVersion + 1,
            ]);
        if ($updated !== 1) {
            throw new RunTransitionConflict('stale_run_version', 'The run changed before its checkpoint could be bound.');
        }

        return Run::query()->findOrFail($run->getKey());
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
        if ($terminal && (! $confirmedStatusOperation instanceof ControlOperation
            || $confirmedStatusOperation->operation_type !== ControlOperationType::TICKET_STATUS_CHANGE
            || $confirmedStatusOperation->state !== ControlOperationState::COMPLETED
            || $confirmedStatusOperation->project_id !== $run->project_id
            || $confirmedStatusOperation->target_control_oid === null
            || ! hash_equals((string) $confirmedStatusOperation->expected_control_commit, $run->run_base_sha))) {
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
        if ($job instanceof ExecutionJob && $this->resumeStep($job)) {
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
}
