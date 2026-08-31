<?php

namespace App\AI6\HumanLoop;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Runs\GateKind;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Runs\Models\TicketApproval;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
        $membership = $this->membership($run);
        $gateEvidenceEffects = $this->gateEvidenceEffects($request, $membership);
        $gateBinding = GateEvidenceHumanRequestBinding::binding($request);

        return view('human-requests.detail', [
            'humanRequest' => $request,
            'run' => $run,
            'ticketId' => $approval instanceof TicketApproval ? $approval->ticket_id : '',
            'cancellationActions' => $this->cancellationActions($run, $membership),
            // Only a request that carries the report-only provenance may offer
            // its own status-saga effects; the identically named cancellation
            // conflict keeps resolving through a re-issued cancellation mode.
            'reportOnlyEffects' => $this->reportOnlyEffects($request, $membership),
            'gateEvidenceEffects' => $gateEvidenceEffects,
            'externalGateEvidenceRequired' => $gateBinding !== null
                && RunGate::query()->where('run_id', $run->id)->where('gate_id', $gateBinding['gate_id'])
                    ->where('kind', GateKind::EXTERNAL->value)->exists(),
            'disposableFindings' => in_array('finding_disposition', $request->allowed_effects, true)
                ? Finding::query()->where('run_id', $run->id)
                    ->whereIn('original_disposition', ['must_fix', 'human_required'])
                    ->orderBy('round_number')->orderBy('id')->get()
                : collect(),
        ]);
    }

    /**
     * The report-only status saga accepts only an approver, so no other role is
     * offered a control that its own service would reject (§6, AC-14).
     *
     * @return list<string>
     */
    private function reportOnlyEffects(HumanRequest $request, ?ProjectMembership $membership): array
    {
        if ($membership?->role !== ProjectRole::APPROVER) {
            return [];
        }

        return array_values(array_filter(
            $request->allowed_effects,
            static fn (string $effect): bool => ReportOnlyHumanRequestBinding::matches($request, $effect),
        ));
    }

    /** @return list<string> */
    private function gateEvidenceEffects(HumanRequest $request, ?ProjectMembership $membership): array
    {
        if (! $membership instanceof ProjectMembership
            || ! app(ProjectPolicy::class)->decisionFor(ProjectAction::AUTHORIZE_GATE_EVIDENCE, $membership->role)) {
            return [];
        }

        return array_values(array_filter(
            $request->allowed_effects,
            static fn (string $effect): bool => GateEvidenceHumanRequestBinding::matches($request, $effect),
        ));
    }

    private function membership(Run $run): ?ProjectMembership
    {
        $userId = Auth::id();

        return is_int($userId) ? ProjectMembership::query()
            ->where('project_id', $run->project_id)->where('user_id', $userId)->first() : null;
    }

    /** @return array<string, string> */
    private function cancellationActions(Run $run, ?ProjectMembership $membership): array
    {
        if ($run->confirmed_branch_publication_oid !== null) {
            return [];
        }
        if (! $membership instanceof ProjectMembership) {
            return [];
        }

        return match ($membership->role) {
            ProjectRole::ADMIN, ProjectRole::OPERATOR => ['soft_cancel' => 'Soft-Cancel'],
            ProjectRole::APPROVER => [
                'block' => 'Fachlich blockieren',
                'hard_cancel' => 'Hard-Cancel',
            ],
            ProjectRole::VIEWER => [],
        };
    }

    private function request(Project $project, string $requestId): HumanRequest
    {
        return HumanRequest::query()->whereKey($requestId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }
}
