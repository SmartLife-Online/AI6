<?php

namespace App\AI6\Tickets;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelUsePolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class TicketMutationController
{
    public const EDIT_STEP_UP_ACTION = 'ticket.edit';

    public const STATUS_STEP_UP_ACTION = 'ticket.status';

    public function edit(Project $project, string $readModel, TicketReadModelFreshness $freshness, TicketReadModelUsePolicy $usePolicy): View
    {
        $model = $this->readModel($project, $readModel);
        abort_unless($usePolicy->allowsEditor($model, ! $freshness->for($project, $model)['stale']), 409);

        return view('tickets.edit', [
            'project' => $project,
            'readModel' => $model,
            'operationId' => (string) Str::uuid(),
            'stepUpAction' => self::EDIT_STEP_UP_ACTION,
        ]);
    }

    public function update(Request $request, Project $project, string $readModel, QueueTicketMutation $action, StepUpGuard $stepUp): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $model = $this->readModel($project, $readModel);
        $validated = $request->validate($this->baseRules() + [
            'target_content' => ['required', 'string', 'max:1048576'],
        ]);
        $stepUp->consumeFresh($request, $actor, self::EDIT_STEP_UP_ACTION);

        try {
            $operation = $action->edit(
                $actor, $project, $model, $validated['operation_id'], $validated['expected_control_oid'],
                $validated['expected_blob'], $validated['base_content'], $validated['target_content'],
                $validated['reason'], true,
            );
        } catch (TicketMutationConflict|ControlOperationConflict $exception) {
            throw ValidationException::withMessages([
                'mutation' => [$exception instanceof TicketMutationConflict
                    ? $exception->getMessage()
                    : 'Die Mutations-ID oder Projektbindung steht in Konflikt mit einem vorhandenen Auftrag.'],
            ]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }

    public function changeStatus(Request $request, Project $project, string $readModel, QueueTicketMutation $action, StepUpGuard $stepUp): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $model = $this->readModel($project, $readModel);
        $validated = $request->validate($this->baseRules() + [
            'status_operation' => ['required', Rule::enum(TicketStatusOperation::class)],
            'external_completion_confirmed' => ['sometimes', 'accepted'],
        ]);
        $stepUp->consumeFresh($request, $actor, self::STATUS_STEP_UP_ACTION);

        try {
            $operation = $action->changeStatus(
                $actor, $project, $model, $validated['operation_id'], $validated['expected_control_oid'],
                $validated['expected_blob'], $validated['base_content'], $validated['reason'], true,
                TicketStatusOperation::from($validated['status_operation']),
                (bool) ($validated['external_completion_confirmed'] ?? false),
            );
        } catch (TicketMutationConflict|ControlOperationConflict $exception) {
            return redirect()->route('projects.tickets.show', [$project, $model])->with('ticket_mutation_conflict', [
                'code' => $exception instanceof TicketMutationConflict ? $exception->conflict : 'operation_binding_conflict',
                'message' => $exception instanceof TicketMutationConflict
                    ? $exception->getMessage()
                    : 'Die Mutations-ID oder Projektbindung steht in Konflikt mit einem vorhandenen Auftrag.',
            ]);
        }

        return redirect()->route('projects.operations.show', [$project, $operation]);
    }

    /** @return array<string, list<string|\Closure>> */
    private function baseRules(): array
    {
        return [
            'operation_id' => ['required', 'uuid'],
            'expected_control_oid' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'expected_blob' => ['required', 'regex:/\A[0-9a-f]{64}\z/D'],
            'base_content' => ['required', 'string', 'max:1048576'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    private function readModel(Project $project, string $readModel): TicketReadModel
    {
        return TicketReadModel::query()->whereKey($readModel)->where('project_id', $project->getKey())->firstOrFail();
    }
}
