<?php

namespace App\AI6\Shared\Process;

final class EffectLockHandle
{
    private bool $released = false;

    /** @param resource $stream */
    public function __construct(
        private mixed $stream,
        public readonly string $name,
        public readonly string $identity,
    ) {}

    public function release(): void
    {
        if (! $this->released && is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
