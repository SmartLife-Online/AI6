<?php

namespace App\AI6\Runs;

use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;

/**
 * The separate change path for instruction and runtime profiles (AC-11).
 *
 * A requested change is never a ticket patch: it parks the run behind
 * contract_change, discards the provider sessions and permits exactly the
 * controlled ticket-status reset to todo — executed by AI6 through the
 * existing status-mutation saga — or a cancel. The old run keeps its
 * run_base_sha; neither the old snapshot nor the old code diff survives into
 * the next run, which requires a new approval and new sessions.
 */
final readonly class ContractChangeService
{
    public function __construct(private RunOrchestrator $orchestrator) {}

    /**
     * Park the run for a requested instruction or runtime profile change.
     *
     * Unexpected external drift of the control base is never absorbed into a
     * contract change: it parks the run behind git_base_changed instead
     * (AC-13), and no session is discarded for it.
     */
    public function request(Run $run, Project $project): Run
    {
        if ($run->project_id !== $project->getKey() || $project->active_run_id !== $run->getKey()) {
            throw new RunTransitionConflict('contract_change_not_bound', 'The contract change does not belong to the active run.');
        }
        if ($run->state !== RunState::RUNNING) {
            throw new RunTransitionConflict('contract_change_not_running', 'Only a running run can enter the contract-change wait.');
        }
        if (! hash_equals($run->run_base_sha, (string) $project->control_oid)) {
            return $this->orchestrator->transition($run, $run->version, RunState::WAITING, $run->phase, WaitReason::GIT_BASE_CHANGED);
        }

        $parked = $this->orchestrator->transition($run, $run->version, RunState::WAITING, $run->phase, WaitReason::CONTRACT_CHANGE);
        $this->orchestrator->discardImplementationSessions($parked);

        return $parked;
    }

    /**
     * Resolve the wait through the confirmed in_progress→todo status saga.
     *
     * The run ends cancelled and releases the project lock; the change becomes
     * effective only through a new approval, a new run and new sessions.
     */
    public function resolveReturnToTodo(Run $run, ControlOperation $confirmedStatusOperation): Run
    {
        if ($run->state !== RunState::WAITING || $run->wait_reason !== WaitReason::CONTRACT_CHANGE) {
            throw new RunTransitionConflict('contract_change_not_waiting', 'The run is not waiting for a contract change.');
        }
        if ($confirmedStatusOperation->operation_type !== ControlOperationType::TICKET_STATUS_CHANGE
            || $confirmedStatusOperation->state !== ControlOperationState::COMPLETED) {
            throw new RunTransitionConflict('contract_change_status_not_confirmed', 'The controlled status reset is not confirmed.');
        }

        return $this->orchestrator->transition(
            $run,
            $run->version,
            RunState::CANCELLED,
            $run->phase,
            null,
            $confirmedStatusOperation,
        );
    }

    /** Cancel the contract-change wait through the existing abort path. */
    public function cancel(Run $run): Run
    {
        return $this->orchestrator->cancelWait($run, $run->version, WaitReason::CONTRACT_CHANGE);
    }
}
