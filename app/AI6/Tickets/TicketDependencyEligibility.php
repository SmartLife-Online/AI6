<?php

namespace App\AI6\Tickets;

final readonly class TicketDependencyEligibility
{
    public function __construct(private TicketDependencyGraph $graph) {}

    /**
     * @param  list<array{id: string, status: string, depends_on: list<string>}>  $tickets
     * @return array{statuses: array<string, string>, reasons: list<string>}
     */
    public function resolve(string $subjectId, array $tickets): array
    {
        $byId = [];
        $duplicates = [];
        foreach ($tickets as $ticket) {
            if (isset($byId[$ticket['id']])) {
                $duplicates[$ticket['id']] = true;
            }
            $byId[$ticket['id']] = $ticket;
        }

        if (! isset($byId[$subjectId])) {
            return ['statuses' => [], 'reasons' => ['ticket_unknown:'.$subjectId]];
        }

        $dependencies = [];
        foreach ($byId as $id => $ticket) {
            $dependencies[$id] = $ticket['depends_on'];
        }
        $graphErrors = $this->graph->validate($dependencies);
        $reachable = $this->reachable($subjectId, $dependencies);
        $reasons = [];

        foreach ($graphErrors as $error) {
            $owner = strstr($error->field, '.', true);
            if (! is_string($owner) || ! isset($reachable[$owner])) {
                continue;
            }
            if ($error->code === 'dependency_cycle') {
                $reasons[] = 'dependency_cycle:'.$owner;
            }
        }

        $statuses = [];
        foreach ($byId[$subjectId]['depends_on'] as $dependency) {
            if (isset($duplicates[$dependency])) {
                $reasons[] = 'dependency_ambiguous:'.$dependency;

                continue;
            }
            if (! isset($byId[$dependency])) {
                $reasons[] = 'dependency_missing:'.$dependency;

                continue;
            }
            if (TicketStatus::tryFrom($byId[$dependency]['status']) === null) {
                $reasons[] = 'dependency_unknown:'.$dependency;

                continue;
            }
            $statuses[$dependency] = $byId[$dependency]['status'];
        }

        ksort($statuses, SORT_STRING);

        return [
            'statuses' => $statuses,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  array<string, list<string>>  $dependencies
     * @return array<string, true>
     */
    private function reachable(string $subjectId, array $dependencies): array
    {
        $reachable = [];
        $pending = [$subjectId];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($reachable[$id])) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($dependencies[$id] ?? [] as $dependency) {
                $pending[] = $dependency;
            }
        }

        return $reachable;
    }
}
