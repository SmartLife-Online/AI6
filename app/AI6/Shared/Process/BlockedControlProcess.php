<?php

namespace App\AI6\Shared\Process;

use Closure;
use LogicException;
use Symfony\Component\Process\InputStream;

final class BlockedControlProcess
{
    private bool $released = false;

    public function __construct(
        private readonly RunningControlProcess $process,
        private readonly InputStream $input,
        public readonly int $processId,
        public readonly float $startedAt,
        private readonly string $releaseOutputPrefix,
        private readonly int $releaseTimeoutSeconds,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            throw new LogicException('A blocked control process can be released only once.');
        }

        $this->released = true;
        $this->input->write("AI6_RELEASE_V1\n");
        $this->input->close();
        if (! $this->process->waitForOutputPrefix($this->releaseOutputPrefix, $this->releaseTimeoutSeconds)) {
            $this->process->cancel();

            throw new LogicException('The blocked control process did not acknowledge its release.');
        }
    }

    public function cancel(): void
    {
        $this->input->close();
        $this->process->cancel();
    }

    /** @param null|Closure(): void $heartbeat */
    public function wait(?Closure $heartbeat = null, int $heartbeatSeconds = 1): ProcessResult
    {
        return $this->process->wait($heartbeat, $heartbeatSeconds);
    }
}
