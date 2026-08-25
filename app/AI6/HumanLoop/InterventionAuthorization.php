<?php

namespace App\AI6\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use Illuminate\Http\Request;

/** A proof object that can only be created after the existing guard succeeds. */
final readonly class InterventionAuthorization
{
    private function __construct(public string $proofHash) {}

    /** @param list<string|int> $binding */
    public static function consumeFresh(
        Request $request,
        User $actor,
        StepUpGuard $guard,
        string $action,
        array $binding,
    ): self {
        $guard->consumeFresh($request, $actor, $action);

        return new self(hash('sha256', implode(':', [
            $action,
            $actor->getKey(),
            $request->session()->getId(),
            ...$binding,
        ])));
    }
}
