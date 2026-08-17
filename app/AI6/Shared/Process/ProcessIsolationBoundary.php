<?php

namespace App\AI6\Shared\Process;

interface ProcessIsolationBoundary
{
    /** @throws ProcessStartRejectedException */
    public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void;
}
