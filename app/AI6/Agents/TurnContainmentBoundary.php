<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;

/** Containment for a FakeAgent turn outside the agent container. */
final readonly class TurnContainmentBoundary implements ProcessIsolationBoundary
{
    public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void
    {
        if ($policy->name !== ProcessPolicyName::AGENT) {
            throw new ProcessStartRejectedException('The turn containment boundary applies to the agent policy.');
        }

        $cwd = realpath($request->workingDirectory);
        if ($cwd === false || is_link($request->workingDirectory) || ! is_dir($cwd)) {
            throw new ProcessStartRejectedException('The agent working directory is unavailable.');
        }

        $git = $cwd.DIRECTORY_SEPARATOR.'.git';
        if (file_exists($git) || is_link($git)) {
            throw new ProcessStartRejectedException('The agent working directory exposes Git metadata.');
        }

        if ($request->resultDirectory === null || $request->artifactDirectory === null) {
            throw new ProcessStartRejectedException('The agent turn requires isolated result and artifact directories.');
        }

        $result = realpath($request->resultDirectory);
        $artifact = realpath($request->artifactDirectory);
        if ($result === false || $artifact === false) {
            throw new ProcessStartRejectedException('The agent result directory is unavailable.');
        }

        $cwdPrefix = rtrim($cwd, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($cwd === $result || str_starts_with($result, $cwdPrefix) || str_starts_with($artifact, $cwdPrefix)) {
            throw new ProcessStartRejectedException('The agent result directory must not lie inside the isolated tree.');
        }
    }
}
