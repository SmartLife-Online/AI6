<?php

namespace App\AI6\Git;

final class ControlOperationRuntimeIdentityFactory
{
    public function fromConfiguredValues(): ControlOperationRuntimeIdentity
    {
        $heartbeatDirectory = getenv('AI6_HEARTBEAT_DIRECTORY');

        return new ControlOperationRuntimeIdentity(
            (string) config('ai6.runtime_role'),
            $heartbeatDirectory === false ? '' : $heartbeatDirectory,
        );
    }
}
