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
}
