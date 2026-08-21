<?php

namespace App\AI6\Runs;

final readonly class ReviewReadinessDecision
{
    /**
     * @param  list<ReviewBlocker>  $blockers
     * @param  list<string>  $openGates
     */
    public function __construct(public array $blockers, public array $openGates) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}
