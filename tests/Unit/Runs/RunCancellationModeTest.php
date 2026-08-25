<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\RunCancellationMode;
use App\AI6\Tickets\TicketStatusOperation;
use PHPUnit\Framework\TestCase;

final class RunCancellationModeTest extends TestCase
{
    public function test_each_mode_has_one_closed_status_operation_and_strong_authorization_contract(): void
    {
        self::assertSame(TicketStatusOperation::RETURN_TO_TODO, RunCancellationMode::SOFT->statusOperation());
        self::assertSame(TicketStatusOperation::BLOCK, RunCancellationMode::BLOCK->statusOperation());
        self::assertSame(TicketStatusOperation::CANCEL, RunCancellationMode::HARD->statusOperation());
        self::assertFalse(RunCancellationMode::SOFT->requiresApprover());
        self::assertTrue(RunCancellationMode::BLOCK->requiresApprover());
        self::assertTrue(RunCancellationMode::HARD->requiresApprover());
    }
}
