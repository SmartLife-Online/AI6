<?php

namespace App\AI6\Runs;

/**
 * What one retention run actually removed, what it deferred for active runs
 * and how many artifact removals failed by name and stay due for the next run.
 */
final readonly class RunRetentionSweepResult
{
    public function __construct(
        public int $artifactsPurged,
        public int $runLogsPurged,
        public int $checkLogsPurged,
        public int $deferred,
        public int $failed = 0,
    ) {}

    public function total(): int
    {
        return $this->artifactsPurged + $this->runLogsPurged + $this->checkLogsPurged;
    }
}
