<?php

namespace Tests\Unit\Tickets;

use App\AI6\Projects\ProjectRole;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketStatusOperation;
use App\AI6\Tickets\TicketStatusTransitionPolicy;
use PHPUnit\Framework\TestCase;

final class TicketApprovalTransitionTest extends TestCase
{
    public function test_approve_is_the_reserved_todo_to_ready_edge_for_approvers(): void
    {
        $sha = str_repeat('a', 64);
        self::assertSame('ready', (new TicketStatusTransitionPolicy)->decide(ProjectRole::APPROVER, TicketStatusOperation::APPROVE, 'todo', true, $sha, $sha, $sha, $sha, false));

        $this->expectException(TicketMutationConflict::class);
        (new TicketStatusTransitionPolicy)->decide(ProjectRole::ADMIN, TicketStatusOperation::APPROVE, 'todo', true, $sha, $sha, $sha, $sha, false);
    }

    public function test_report_only_completion_owns_exactly_in_progress_to_ready(): void
    {
        $sha = str_repeat('b', 64);
        $policy = new TicketStatusTransitionPolicy;

        self::assertSame('ready', $policy->decideReportOnly(
            ProjectRole::APPROVER,
            'in_progress',
            $sha,
            $sha,
            $sha,
            $sha,
        ));
        self::assertNull(TicketStatusOperation::APPROVE->targetFor('in_progress'));
        self::assertNull(TicketStatusOperation::COMPLETE_REPORT_ONLY->targetFor('ready'));

        try {
            $policy->decideReportOnly(ProjectRole::APPROVER, 'ready', $sha, $sha, $sha, $sha);
            self::fail('The report-only edge must reject every source except in_progress.');
        } catch (TicketMutationConflict $conflict) {
            self::assertSame('transition_not_authorized', $conflict->conflict);
        }
    }
}
