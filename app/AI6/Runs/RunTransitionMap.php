<?php

namespace App\AI6\Runs;

final class RunTransitionMap
{
    /** @var array<string, list<RunState>> */
    private const STATES = [
        'queued' => [RunState::RUNNING, RunState::FAILED, RunState::CANCELLED],
        'running' => [RunState::WAITING, RunState::FAILED, RunState::COMPLETED, RunState::CANCELLED],
        'waiting' => [RunState::RUNNING, RunState::FAILED, RunState::CANCELLED],
        'failed' => [RunState::WAITING, RunState::CANCELLED],
        'completed' => [],
        'cancelled' => [],
    ];

    /** @var array<string, list<string>> */
    private const TERMINAL_TICKET_STATUSES = [
        'completed' => ['review', 'ready'],
        'cancelled' => ['todo', 'blocked', 'cancelled'],
    ];

    public function assertState(RunState $from, RunState $to): void
    {
        if (! in_array($to, self::STATES[$from->value], true)) {
            throw new RunTransitionConflict('invalid_state_transition', 'The requested run state transition is not permitted.');
        }
    }

    /**
     * The base-drift park is reachable from every non-terminal state.
     *
     * Unexpected drift of the control base is the honest answer regardless of
     * what the run was doing (RUN-007, plan §8.2): a queued run, a running run
     * and a run that already waits — for example behind contract_change while
     * its amendment compare-and-swap fails — all have to reach
     * git_base_changed. The generic state map deliberately does not allow
     * queued → waiting or a re-park of a waiting run, so this one named
     * exception carries it and nothing else.
     */
    public function assertBaseDriftPark(RunState $from): void
    {
        if (in_array($from, [RunState::COMPLETED, RunState::CANCELLED], true)) {
            throw new RunTransitionConflict('run_terminal', 'A terminal run cannot be parked behind a base drift.');
        }
    }

    public function assertWait(RunState $state, ?WaitReason $reason): void
    {
        if (($state === RunState::WAITING) !== ($reason instanceof WaitReason)) {
            throw new RunTransitionConflict('invalid_wait_binding', 'A wait reason is required exactly while the run is waiting.');
        }
    }

    public function assertTerminalTicketStatus(RunState $state, string $ticketStatus): void
    {
        if (! in_array($ticketStatus, self::TERMINAL_TICKET_STATUSES[$state->value] ?? [], true)) {
            throw new RunTransitionConflict('terminal_status_target_conflict', 'The confirmed ticket status does not match the requested terminal run state.');
        }
    }
}
