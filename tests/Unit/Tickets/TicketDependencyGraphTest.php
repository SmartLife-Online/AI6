<?php

namespace Tests\Unit\Tickets;

use App\AI6\Tickets\TicketDependencyGraph;
use PHPUnit\Framework\TestCase;

final class TicketDependencyGraphTest extends TestCase
{
    public function test_cycle_self_reference_and_missing_ticket_are_deterministic(): void
    {
        $errors = (new TicketDependencyGraph)->validate([
            'AI6-001' => ['AI6-002'],
            'AI6-002' => ['AI6-001'],
            'AI6-003' => ['AI6-003'],
            'AI6-004' => ['AI6-999'],
        ]);
        $codes = array_map(fn ($error) => $error->code, $errors);
        self::assertContains('dependency_cycle', $codes);
        self::assertContains('dependency_self_reference', $codes);
        self::assertContains('dependency_missing', $codes);
        self::assertSame($codes, array_map(fn ($error) => $error->code, (new TicketDependencyGraph)->validate([
            'AI6-001' => ['AI6-002'], 'AI6-002' => ['AI6-001'], 'AI6-003' => ['AI6-003'], 'AI6-004' => ['AI6-999'],
        ])));
    }
}
