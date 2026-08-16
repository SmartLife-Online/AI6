<?php

namespace App\AI6\Runs;

use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The exclusive mutation boundary for persisted run state. */
final readonly class RunOrchestrator
{
    public function __construct(private RunTransitionMap $transitions) {}

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

            return $run;
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
}
