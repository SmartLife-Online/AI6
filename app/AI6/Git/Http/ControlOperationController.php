<?php

namespace App\AI6\Git\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\RecordRecoveryDecision;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ControlOperationController
{
    public function provision(
        Request $request,
        Project $project,
        QueueDeployKeyProvisioning $action,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $validated = $request->validate([
            'operation_id' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! ManagedProjectPath::validOperationIdentifier($value)) {
                        $fail('Die Operations-ID ist ungültig.');
                    }
                },
            ],
        ]);

        try {
            $operation = $action->handle($actor, $project, $validated['operation_id']);
        } catch (ControlOperationConflict) {
            throw ValidationException::withMessages([
                'operation_id' => ['Die Provisionierung steht in Konflikt mit dem aktuellen Projektzustand.'],
            ]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }

    public function show(Project $project, ControlOperation $operation): View
    {
        abort_unless($operation->project_id === $project->getKey(), 404);
        $operation->load(['result', 'recoveryDecision', 'recoveryDecisions.actor']);

        return view('projects.operation', compact('project', 'operation'));
    }

    public function clone(
        Request $request,
        Project $project,
        QueueManagedCloneOperation $action,
    ): RedirectResponse {
        return $this->enqueueManagedClone($request, $project, $action, ControlOperationType::MANAGED_CLONE);
    }

    public function fetch(
        Request $request,
        Project $project,
        QueueManagedCloneOperation $action,
    ): RedirectResponse {
        return $this->enqueueManagedClone($request, $project, $action, ControlOperationType::MANAGED_FETCH);
    }

    public function recover(
        Request $request,
        Project $project,
        ControlOperation $operation,
        RecordRecoveryDecision $action,
    ): RedirectResponse {
        abort_unless($operation->project_id === $project->getKey(), 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $validated = $request->validate([
            'decision' => ['required', Rule::enum(RecoveryDecisionType::class)],
            'expected_attempt_token' => ['required', 'integer', 'min:1'],
            'expected_operation_version' => ['required', 'integer', 'min:0'],
            'finding_hash' => ['required', 'regex:/\\A[0-9a-f]{64}\\z/D'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'evidence_commit' => ['nullable', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'evidence_base_commit' => ['nullable', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'evidence_diff_sha256' => ['nullable', 'regex:/\A[0-9a-f]{64}\z/D'],
        ]);

        try {
            $action->handle(
                $request,
                $actor,
                $operation,
                RecoveryDecisionType::from($validated['decision']),
                $validated['expected_attempt_token'],
                $validated['expected_operation_version'],
                $validated['finding_hash'],
                $validated['reason'] ?? null,
                [
                    'commit' => $validated['evidence_commit'] ?? null,
                    'base_commit' => $validated['evidence_base_commit'] ?? null,
                    'diff_sha256' => $validated['evidence_diff_sha256'] ?? null,
                ],
            );
        } catch (ControlOperationConflict) {
            throw ValidationException::withMessages([
                'decision' => ['Die Recovery-Entscheidung ist veraltet oder unvollständig.'],
            ]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }

    private function enqueueManagedClone(
        Request $request,
        Project $project,
        QueueManagedCloneOperation $action,
        ControlOperationType $type,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $validated = $request->validate([
            'operation_id' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! ManagedProjectPath::validOperationIdentifier($value)) {
                        $fail('Die Operations-ID ist ungültig.');
                    }
                },
            ],
        ]);

        try {
            $operation = $action->handle($actor, $project, $type, $validated['operation_id']);
        } catch (ControlOperationConflict) {
            throw ValidationException::withMessages([
                'operation_id' => ['Die Managed-Clone-Operation steht in Konflikt mit dem aktuellen Projektzustand.'],
            ]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }
}
