<?php

namespace App\AI6\Checks;

use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;

/**
 * Containment for a check executed outside the checker container.
 *
 * Inside the checker role the full ProcessIsolationVerifier applies. Outside it
 * — on the worker and in the test runtime — this boundary still enforces the
 * part that is not a property of the container: no reachable Git metadata of
 * the managed clone in the checked tree (GIT-010), and result and artifact
 * directories that lie outside the tree the check may write to.
 */
final readonly class CheckContainmentBoundary implements ProcessIsolationBoundary
{
    /** Git metadata of a managed clone, none of which may be reachable from a check. */
    private const GIT_METADATA = ['.git', 'objects', 'refs', 'index', 'hooks', 'HEAD', 'commondir', 'alternates'];

    public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void
    {
        if ($policy->name !== ProcessPolicyName::CHECKER) {
            throw new ProcessStartRejectedException('The check containment boundary applies to the checker policy.');
        }

        $cwd = realpath($request->workingDirectory);
        if ($cwd === false || is_link($request->workingDirectory) || ! is_dir($cwd)) {
            throw new ProcessStartRejectedException('The checker working directory is unavailable.');
        }

        foreach (self::GIT_METADATA as $entry) {
            $path = $cwd.DIRECTORY_SEPARATOR.$entry;
            if (file_exists($path) || is_link($path)) {
                throw new ProcessStartRejectedException('The checker working directory exposes Git metadata.');
            }
        }

        if ($request->resultDirectory === null || $request->artifactDirectory === null) {
            throw new ProcessStartRejectedException('A check requires isolated result and artifact directories.');
        }

        $result = realpath($request->resultDirectory);
        $artifact = realpath($request->artifactDirectory);
        if ($result === false || $artifact === false) {
            throw new ProcessStartRejectedException('The checker result directory is unavailable.');
        }

        $prefix = rtrim($cwd, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if ($cwd === $result || str_starts_with($result, $prefix) || str_starts_with($artifact, $prefix)) {
            throw new ProcessStartRejectedException('The checker result directory must not lie inside the checked tree.');
        }
    }
}
