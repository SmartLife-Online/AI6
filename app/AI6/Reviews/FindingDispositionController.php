<?php

namespace App\AI6\Reviews;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunTransitionConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class FindingDispositionController
{
    public const STEP_UP_ACTION = 'finding.dispose';

    public function store(
        Request $request,
        Project $project,
        string $runId,
        string $findingId,
        StepUpGuard $stepUp,
        RunOrchestrator $orchestrator,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $validated = $request->validate([
            'disposition' => ['required', Rule::in([
                FindingDispositionType::NOT_APPLICABLE->value,
                FindingDispositionType::ACCEPTED_RISK->value,
            ])],
            'reason' => ['required', 'string', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ]);
        $run = Run::query()->whereKey($runId)->where('project_id', $project->id)->firstOrFail();
        $finding = Finding::query()->whereKey($findingId)->where('run_id', $run->id)->firstOrFail();

        try {
            $stepUp->consumeFresh($request, $actor, self::STEP_UP_ACTION);
            $proofHash = hash('sha256', implode(':', [
                self::STEP_UP_ACTION,
                $actor->getKey(),
                $request->session()->getId(),
                $run->id,
                $finding->id,
                $validated['expected_version'],
            ]));
            $orchestrator->recordHumanFindingDisposition(
                $run,
                $finding,
                (int) $validated['expected_version'],
                FindingDispositionType::from($validated['disposition']),
                $validated['reason'],
                $actor,
                $proofHash,
            );
        } catch (RunTransitionConflict $exception) {
            throw ValidationException::withMessages([
                'disposition' => ['Die Finding-Disposition wurde abgelehnt: '.$exception->reason.'.'],
            ]);
        }

        return redirect()->route('projects.runs.show', [$project, $run->id]);
    }
}
