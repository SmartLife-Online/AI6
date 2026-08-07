<?php

namespace App\AI6\Git;

final readonly class ControlOperationRuntimeIdentity
{
    public function __construct(
        public string $runtimeRole,
        public string $heartbeatDirectory,
    ) {}
}
