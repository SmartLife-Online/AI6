<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Runs\RunTransitionMap;
use App\AI6\Runs\WaitReason;
use PHPUnit\Framework\TestCase;

final class RunTransitionMapTest extends TestCase
{
    public function test_the_state_and_wait_contract_is_closed(): void
    {
        $map = new RunTransitionMap;
        self::assertSame(['queued', 'running', 'waiting', 'failed', 'completed', 'cancelled'], array_map(static fn (RunState $state): string => $state->value, RunState::cases()));
        self::assertSame(['prepare', 'implement', 'check', 'review', 'fix', 'finalize', 'security_review', 'publish'], array_map(static fn (RunPhase $phase): string => $phase->value, RunPhase::cases()));
        self::assertSame(['human_question', 'scope_approval', 'contract_change', 'review_limit', 'resource_limit', 'provider_error', 'invalid_json', 'check_failure', 'manual_gate', 'security_gate', 'git_base_changed', 'git_conflict', 'manual_push', 'manual_report', 'status_sync'], array_map(static fn (WaitReason $reason): string => $reason->value, WaitReason::cases()));
        $map->assertWait(RunState::WAITING, WaitReason::MANUAL_GATE);
        $allowed = [
            'queued:running', 'queued:failed', 'queued:cancelled',
            'running:waiting', 'running:failed', 'running:completed', 'running:cancelled',
            'waiting:running', 'waiting:failed', 'waiting:cancelled',
            'failed:waiting', 'failed:cancelled',
        ];
        foreach (RunState::cases() as $from) {
            foreach (RunState::cases() as $to) {
                $pair = $from->value.':'.$to->value;
                if (in_array($pair, $allowed, true)) {
                    $map->assertState($from, $to);

                    continue;
                }
                try {
                    $map->assertState($from, $to);
                    self::fail($pair.' must be rejected.');
                } catch (RunTransitionConflict) {
                }
            }
        }
    }

    public function test_a_wait_reason_is_bound_exactly_to_waiting(): void
    {
        $map = new RunTransitionMap;

        $this->expectException(RunTransitionConflict::class);
        $map->assertWait(RunState::RUNNING, WaitReason::MANUAL_GATE);
    }

    public function test_terminal_run_states_have_closed_ticket_status_edges(): void
    {
        $map = new RunTransitionMap;
        $allowed = [
            'completed:review', 'completed:ready',
            'cancelled:todo', 'cancelled:blocked', 'cancelled:cancelled',
        ];
        foreach (RunState::cases() as $state) {
            foreach (['todo', 'ready', 'in_progress', 'blocked', 'review', 'done', 'cancelled'] as $ticketStatus) {
                $pair = $state->value.':'.$ticketStatus;
                if (in_array($pair, $allowed, true)) {
                    $map->assertTerminalTicketStatus($state, $ticketStatus);

                    continue;
                }
                try {
                    $map->assertTerminalTicketStatus($state, $ticketStatus);
                    self::fail($pair.' must be rejected.');
                } catch (RunTransitionConflict $conflict) {
                    self::assertSame('terminal_status_target_conflict', $conflict->reason);
                }
            }
        }
    }
}
