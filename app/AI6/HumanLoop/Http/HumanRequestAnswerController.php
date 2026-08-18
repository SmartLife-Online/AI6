<?php

namespace App\AI6\HumanLoop\Http;

use App\AI6\Auth\Models\User;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HumanRequestAnswerController
{
    public function store(
        Request $request,
        Project $project,
        string $requestId,
        HumanRequestService $service,
    ): RedirectResponse {
        Gate::authorize('answerHumanRequest', $project);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $humanRequest = HumanRequest::query()
            ->whereKey($requestId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();

        $validated = $request->validate([
            'run_version' => ['required', 'integer'],
            'ticket_contract' => ['required', 'string'],
            'checkpoint' => ['required', 'string'],
            'scope' => ['required', 'string'],
            'agent_slot' => ['required', 'string'],
            'requested_effect' => ['required', 'string'],
            'chosen_effect' => ['required', 'string'],
        ]);

        try {
            $service->answer(
                $humanRequest,
                $user,
                (int) $validated['run_version'],
                $validated['ticket_contract'],
                $validated['checkpoint'],
                $validated['scope'],
                $validated['agent_slot'],
                $validated['requested_effect'],
                $validated['chosen_effect'],
            );
        } catch (HumanRequestRejected $rejected) {
            return back()->withErrors(['chosen_effect' => $this->message($rejected->reason)]);
        }

        return redirect()
            ->route('projects.runs.show', [$project, $humanRequest->run_id])
            ->with('status', 'Die Antwort wurde übernommen.');
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'unauthorized' => 'Keine Berechtigung für diese Antwort.',
            'request_already_resolved' => 'Diese Anfrage ist bereits beantwortet.',
            'stale_run_version' => 'Die Runversion ist veraltet.',
            'stale_ticket_contract' => 'Der Ticketvertrag weicht von der Bindung ab.',
            'stale_checkpoint' => 'Der Checkpoint weicht von der Bindung ab.',
            'stale_scope' => 'Der Scope weicht von der Bindung ab.',
            'stale_agent_slot' => 'Der Agentenslot weicht von der Bindung ab.',
            'stale_requested_effect' => 'Die angeforderte Wirkung weicht von der Bindung ab.',
            'effect_not_offered' => 'Die gewählte Wirkung ist nicht zulässig.',
            'attention_user_unavailable' => 'Es ist kein aktiver Attention-User gebunden.',
            'checkpoint_not_bound' => 'Der Run besitzt keinen gebundenen Checkpoint.',
            'bound_step_not_parkable' => 'Der gebundene Schritt konnte nicht geparkt werden.',
            default => 'Die Antwort wurde ohne Wirkung abgewiesen.',
        };
    }
}
