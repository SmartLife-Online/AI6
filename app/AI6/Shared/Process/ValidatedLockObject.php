<?php

namespace App\AI6\Shared\Process;

final readonly class ValidatedLockObject
{
    public function __construct(
        public EffectLockOutcome $outcome,
        public ?string $path,
        public ?string $identity,
        public string $message,
    ) {}

    public function valid(): bool
    {
        return $this->outcome === EffectLockOutcome::ACQUIRED;
    }
}
