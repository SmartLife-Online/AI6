<?php

namespace App\AI6\Runs;

final readonly class ReviewOnlyCompletionDecision
{
    /** @param list<string> $blockers */
    public function __construct(public array $blockers) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}
