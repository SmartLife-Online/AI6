<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The route parameter is requestId, not request: a parameter that matches a
 * public model property would be resolved before the policy middleware.
 */
#[Layout('layouts.app', ['title' => 'Human Request – AI6'])]
final class HumanRequestDetailPage extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public string $requestId = '';

    public function mount(Project $project, string $requestId): void
    {
        Gate::authorize('answerHumanRequest', $project);
        $this->project = $project;
        $this->requestId = $this->request($project, $requestId)->id;
    }

    public function render(): View
    {
        Gate::authorize('answerHumanRequest', $this->project);
        $request = $this->request($this->project, $this->requestId);
        $run = Run::query()->findOrFail($request->run_id);
        $approval = TicketApproval::query()->find($run->ticket_approval_id);

        return view('human-requests.detail', [
            'humanRequest' => $request,
            'run' => $run,
            'ticketId' => $approval instanceof TicketApproval ? $approval->ticket_id : '',
        ]);
    }

    private function request(Project $project, string $requestId): HumanRequest
    {
        return HumanRequest::query()->whereKey($requestId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }
}
