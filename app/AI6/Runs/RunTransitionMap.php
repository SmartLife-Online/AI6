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
