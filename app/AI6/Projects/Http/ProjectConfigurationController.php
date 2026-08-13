<?php

namespace App\AI6\Projects\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Projects\Actions\ApproveProjectConfiguration;
use App\AI6\Projects\Actions\QueueProjectConfigRefresh;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\ProjectConfigurationConflict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class ProjectConfigurationController
{
    public const APPROVAL_STEP_UP_ACTION = 'project_config.approve';

    public function refresh(Request $request, Project $project, QueueProjectConfigRefresh $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $validated = $request->validate(['operation_id' => ['required', 'uuid']]);
        try {
            $operation = $action->handle($actor, $project, $validated['operation_id']);
        } catch (ControlOperationConflict) {
            throw ValidationException::withMessages(['operation_id' => ['Der Config-Refresh steht in Konflikt mit einem vorhandenen Auftrag.']]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }

    public function approve(
        Request $request,
        Project $project,
        string $draft,
        ApproveProjectConfiguration $action,
        StepUpGuard $stepUp,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $model = ProjectConfigDraft::query()->whereKey($draft)->where('project_id', $project->getKey())->firstOrFail();
        $validated = $request->validate([
            'approval_id' => ['required', 'uuid'],
            'expected_control_commit' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_blob_sha' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_config_hash' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_control_generation' => ['required', 'integer', 'min:0'],
        ]);
        $stepUp->consumeFresh($request, $actor, self::APPROVAL_STEP_UP_ACTION);
        try {
            $action->handle($actor, $project, $model, $validated['approval_id'], $validated['expected_control_commit'],
                $validated['expected_blob_sha'], $validated['expected_config_hash'], (int) $validated['expected_control_generation']);
        } catch (ProjectConfigurationConflict $exception) {
            throw ValidationException::withMessages(['approval' => [$exception->getMessage()]]);
        }

        return redirect()->route('projects.show', $project);
    }
}
