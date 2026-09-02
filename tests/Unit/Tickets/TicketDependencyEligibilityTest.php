<?php

namespace Tests\Unit\Tickets;

use App\AI6\Tickets\TicketDependencyEligibility;
use App\AI6\Tickets\TicketDependencyGraph;
use PHPUnit\Framework\TestCase;

final class TicketDependencyEligibilityTest extends TestCase
{
    public function test_it_names_satisfied_unsatisfied_missing_unknown_ambiguous_and_cyclic_dependencies(): void
    {
        $resolver = new TicketDependencyEligibility(new TicketDependencyGraph);

        self::assertSame(
            ['statuses' => ['B' => 'done'], 'reasons' => []],
            $resolver->resolve('A', $this->tickets(['A' => ['ready', ['B']], 'B' => ['done', []]])),
        );
        self::assertSame(
            ['statuses' => ['B' => 'todo'], 'reasons' => []],
            $resolver->resolve('A', $this->tickets(['A' => ['ready', ['B']], 'B' => ['todo', []]])),
        );
        self::assertSame(
            ['statuses' => [], 'reasons' => ['dependency_missing:B']],
            $resolver->resolve('A', $this->tickets(['A' => ['ready', ['B']]])),
        );
        self::assertSame(
            ['statuses' => [], 'reasons' => ['dependency_unknown:B']],
            $resolver->resolve('A', $this->tickets(['A' => ['ready', ['B']], 'B' => ['foreign', []]])),
        );

        $ambiguous = $this->tickets(['A' => ['ready', ['B']], 'B' => ['done', []]]);
        $ambiguous[] = ['id' => 'B', 'status' => 'review', 'depends_on' => []];
        self::assertSame(
            ['statuses' => [], 'reasons' => ['dependency_ambiguous:B']],
            $resolver->resolve('A', $ambiguous),
        );

        $cycle = $resolver->resolve('A', $this->tickets([
            'A' => ['ready', ['B']],
            'B' => ['todo', ['A']],
        ]));
        self::assertSame(['B' => 'todo'], $cycle['statuses']);
        self::assertNotSame([], array_filter($cycle['reasons'], static fn (string $reason): bool => str_starts_with($reason, 'dependency_cycle:')));
    }

    /**
     * @param  array<string, array{string, list<string>}>  $definitions
     * @return list<array{id: string, status: string, depends_on: list<string>}>
     */
    private function tickets(array $definitions): array
    {
        $tickets = [];
        foreach ($definitions as $id => [$status, $dependsOn]) {
            $tickets[] = compact('id', 'status', 'dependsOn') + ['depends_on' => $dependsOn];
            unset($tickets[array_key_last($tickets)]['dependsOn']);
        }

        return $tickets;
    }
}
