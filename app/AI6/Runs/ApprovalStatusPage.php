<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Approvalstatus – AI6'])]
final class ApprovalStatusPage extends Component
{
    public Project $project;

    public TicketApproval $approval;

    #[Locked]
    public ?string $evaluationId = null;

    public function mount(Project $project, string $approvalId): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
        $this->approval = TicketApproval::query()
            ->whereKey($approvalId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }

    public function hydrate(): void
    {
        Gate::authorize('view', $this->project);
        abort_unless($this->approval->project_id === $this->project->getKey(), 404);
    }

    public function requestEvaluation(): void
    {
        Gate::authorize('view', $this->project);
        abort_unless($this->approval->project_id === $this->project->getKey(), 404);
        $evaluation = DB::transaction(function (): TicketApprovalEvaluation {
            $evaluation = TicketApprovalEvaluation::query()->firstOrCreate(
                [
                    'ticket_approval_id' => $this->approval->getKey(),
                    'state' => 'queued',
                ],
                [
                    'id' => (string) Str::uuid(),
                ],
            );
            if ($evaluation->wasRecentlyCreated) {
                Queue::connection('database')->push(new EvaluateTicketApproval($evaluation->id));
            }

            return $evaluation;
        });
        $this->evaluationId = $evaluation->id;
    }

    public function render(): View
    {
        $readModel = TicketReadModel::query()
            ->where('project_id', $this->project->getKey())
            ->where('relative_path', $this->approval->relative_path)
            ->first();
        $evaluation = $this->evaluationId === null ? null : TicketApprovalEvaluation::query()
            ->whereKey($this->evaluationId)
            ->where('ticket_approval_id', $this->approval->getKey())
            ->first();
        $eligibility = match ($evaluation?->state) {
            'ready', 'conflict' => [
                'eligible' => $evaluation->eligible === true,
                'reasons' => is_array($evaluation->reasons) ? $evaluation->reasons : ['approval_evaluation_failed'],
            ],
            'queued' => ['eligible' => false, 'reasons' => ['evaluation_pending']],
            default => ['eligible' => false, 'reasons' => ['evaluation_required']],
        };

        return view('approvals.status', [
            'readModel' => $readModel,
            'eligibility' => $eligibility,
            'evaluationState' => $evaluation?->state,
        ]);
    }
}
