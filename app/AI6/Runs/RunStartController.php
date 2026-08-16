<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class RunStartController
{
    public function store(Request $request, Project $project, string $approvalId, ProjectPolicy $policy, QueueRunStart $starts): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $policy->startRun($actor, $project), 403);
        try {
            $operation = $starts->handle($actor, $project, $approvalId, (string) Str::uuid());
        } catch (ControlOperationConflict $exception) {
            throw ValidationException::withMessages(['run' => ['Die Approval ist nicht startbereit.']]);
        }

        // A browser may request a typed start only. The worker performs the fresh
        // eligibility check and the Git status saga before RunOrchestrator finalizes it.
        return redirect()->route('projects.operations.show', [$project, $operation]);
    }
}
