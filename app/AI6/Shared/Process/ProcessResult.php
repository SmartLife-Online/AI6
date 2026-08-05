<?php

namespace App\AI6\Shared\Process;

final readonly class ProcessResult
{
    public function __construct(
        public ProcessOutcome $outcome,
        public ?int $exitCode,
        public string $output,
        public string $errorOutput,
        public float $durationSeconds,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === ProcessOutcome::SUCCEEDED;
    }
}
