<?php

namespace App\AI6\Shared\Process;

final readonly class ProcessLimits
{
    public function __construct(
        public int $runtimeSeconds,
        public int $outputBytes,
        public int $processCount,
        public int $fileCount,
        public int $totalBytes,
        public int $artifactCount,
    ) {
        foreach ((array) $this as $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Every process limit must be positive.');
            }
        }
    }

    public function restrict(?self $approval): self
    {
        if ($approval === null) {
            return $this;
        }

        return new self(
            min($this->runtimeSeconds, $approval->runtimeSeconds),
            min($this->outputBytes, $approval->outputBytes),
            min($this->processCount, $approval->processCount),
            min($this->fileCount, $approval->fileCount),
            min($this->totalBytes, $approval->totalBytes),
            min($this->artifactCount, $approval->artifactCount),
        );
    }
}
