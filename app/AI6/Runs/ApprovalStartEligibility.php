<?php

namespace App\AI6\Runs;

final readonly class ApprovalStartEligibility
{
    /**
     * @param  list<string>  $stalenessReasons
     * @param  array<string, string>  $dependencyStatuses
     * @param  list<string>  $satisfiedStatuses
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function decide(array $stalenessReasons, array $dependencyStatuses, array $satisfiedStatuses, bool $capabilitiesAvailable, bool $unfinishedRunExists, string $queueState): array
    {
        $reasons = $stalenessReasons;
        if (! $capabilitiesAvailable) {
            $reasons[] = 'provider_capability_unavailable';
        }
        foreach ($dependencyStatuses as $ticketId => $status) {
            if (! in_array($status, $satisfiedStatuses, true)) {
                $reasons[] = 'dependency_unsatisfied:'.$ticketId;
            }
        }
        if ($unfinishedRunExists) {
            $reasons[] = 'unfinished_run_exists';
        }
        if ($queueState !== 'queued') {
            $reasons[] = 'approval_not_queued';
        }
        $reasons = array_values(array_unique($reasons));

        return ['eligible' => $reasons === [], 'reasons' => $reasons];
    }
}
