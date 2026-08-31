<?php

namespace App\AI6\Runs;

final readonly class CandidateGateDecision
{
    /** @param list<string> $blockers
     * @param  list<string>  $openGates
     */
    public function __construct(public array $blockers, public array $openGates) {}

    public function ready(): bool
    {
        return $this->blockers === [] && $this->openGates === [];
    }
}
