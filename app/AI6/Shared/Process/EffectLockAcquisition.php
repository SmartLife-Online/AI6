<?php

namespace App\AI6\Shared\Process;

final readonly class EffectLockAcquisition
{
    public function __construct(
        public EffectLockOutcome $outcome,
        public ?EffectLockHandle $handle,
        public string $message,
    ) {}

    public function acquired(): bool
    {
        return $this->outcome === EffectLockOutcome::ACQUIRED && $this->handle !== null;
    }
}
