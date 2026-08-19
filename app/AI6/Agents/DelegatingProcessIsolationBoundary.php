<?php

namespace App\AI6\Agents;

use App\AI6\Checks\CheckContainmentBoundary;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessIsolationVerifier;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;

/**
 * Dispatch isolation by request policy: verifier in-role, containment otherwise.
 *
 * In its own role a request reaches the full ProcessIsolationVerifier. Outside
 * it, the agent policy uses the FakeAgent turn containment and the checker
 * policy the check containment (AI6-021, human-released scope decision): both
 * still enforce the part that is not a property of the container — no reachable
 * Git metadata of the managed clone, and result/artifact directories outside the
 * tree the process may write to. A policy without such a boundary stays refused.
 */
final readonly class DelegatingProcessIsolationBoundary implements ProcessIsolationBoundary
{
    /**
     * The parameters name the concrete collaborators on purpose. This class is
     * the container binding for ProcessIsolationBoundary, so an interface-typed
     * parameter would resolve back to this class and recurse until the process
     * dies.
     */
    public function __construct(
        private ProcessIsolationVerifier $verifier = new ProcessIsolationVerifier,
        private TurnContainmentBoundary $containment = new TurnContainmentBoundary,
        private CheckContainmentBoundary $checkContainment = new CheckContainmentBoundary,
    ) {}

    public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void
    {
        if (config('ai6.runtime_role') === $policy->name->value) {
            $this->verifier->assertIsolated($request, $policy);

            return;
        }

        if ($policy->name === ProcessPolicyName::AGENT) {
            $this->containment->assertIsolated($request, $policy);

            return;
        }

        if ($policy->name === ProcessPolicyName::CHECKER) {
            $this->checkContainment->assertIsolated($request, $policy);

            return;
        }

        throw new ProcessStartRejectedException('The process policy is not isolated for the current runtime role.');
    }
}
