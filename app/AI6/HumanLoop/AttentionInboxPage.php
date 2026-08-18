<?php

namespace App\AI6\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\WaitReason;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Attention-Inbox – AI6'])]
final class AttentionInboxPage extends Component
{
    public function render(ProjectPolicy $policy): View
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        return view('human-requests.inbox', [
            'requests' => $this->openRequests($user, $policy),
        ]);
    }

    /** @return Collection<int, array{request: HumanRequest, project: string, ticket: string, wait_reason: string}> */
    private function openRequests(User $user, ProjectPolicy $policy): Collection
    {
        $projectIds = ProjectMembership::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->filter(static fn (ProjectMembership $membership): bool => $policy->decisionFor(
                ProjectAction::ANSWER_HUMAN_REQUEST,
                $membership->role,
            ))
            ->pluck('project_id')
            ->all();

        if ($projectIds === []) {
            return collect();
        }

        $rows = collect();
        /** @var Collection<int, HumanRequest> $requests */
        $requests = HumanRequest::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->where('resolution_state', HumanRequestResolutionState::OPEN)
            ->orderBy('created_at')
            ->get();

        // Run and approval are read in one batch each: the inbox is a read
        // surface and must not scale its query count with its row count.
        /** @var Collection<string, Run> $runs */
        $runs = Run::query()->whereIn('id', $requests->pluck('run_id')->unique()->all())->get()->keyBy('id');
        /** @var Collection<string, TicketApproval> $approvals */
        $approvals = TicketApproval::query()
            ->whereIn('id', $runs->pluck('ticket_approval_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($requests as $request) {
            $run = $runs->get($request->run_id);
            $approval = $run instanceof Run ? $approvals->get($run->ticket_approval_id) : null;

            $rows->push([
                'request' => $request,
                'project' => (string) ($request->project->name ?? ''),
                'ticket' => $approval instanceof TicketApproval ? $approval->ticket_id : '',
                'wait_reason' => $run instanceof Run && $run->wait_reason instanceof WaitReason
                    ? $run->wait_reason->value
                    : '',
            ]);
        }

        return $rows;
    }
}
