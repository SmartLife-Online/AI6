<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessIsolationVerifier;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;

/** Dispatch isolation by request policy: verifier in-role, FakeAgent containment otherwise. */
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

        throw new ProcessStartRejectedException('The process policy is not isolated for the current runtime role.');
    }
}
