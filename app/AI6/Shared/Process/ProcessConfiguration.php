<?php

namespace App\AI6\Shared\Process;

final readonly class ProcessConfiguration
{
    public function __construct(
        public int $timeoutSeconds,
        public int $outputLimitBytes,
        public int $cancelGraceMilliseconds,
        public int $wrapperReadyTimeoutSeconds,
        public string $wrapperScript,
        public string $shellBinary,
        public ?string $setsidBinary,
        public ?string $processGroupKillBinary,
        public string $lockDirectory,
        public int $lockObjectCount,
        public int $lockWaitMilliseconds,
        public int $lockOwnerUid,
    ) {}
}
