<?php

namespace App\AI6\Shared\Process;

final readonly class BlockedProcessStartResult
{
    public function __construct(
        public BlockedStartOutcome $outcome,
        public ?BlockedControlProcess $process,
        public string $message,
    ) {}

    public function ready(): bool
    {
        return $this->outcome === BlockedStartOutcome::READY && $this->process !== null;
    }
}
