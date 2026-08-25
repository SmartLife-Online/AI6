<?php

namespace App\AI6\HumanLoop\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Auth\StepUpRequiredException;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\RunCancellationMode;
use App\AI6\Runs\RunCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HumanRequestAnswerController
{
    public const STEP_UP_ACTION = 'run.intervene';

    public function store(
        Request $request,
        Project $project,
        string $requestId,
        HumanRequestService $service,
        StepUpGuard $stepUp,
        RunCancellationService $cancellations,
    ): RedirectResponse {
        Gate::authorize('interveneRun', $project);

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
            'reason' => ['nullable', 'string', 'max:2000'],
            'finding_id' => ['nullable', 'uuid'],
            'finding_disposition' => ['nullable', 'string'],
            'disposition_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $mode = RunCancellationMode::tryFrom($validated['chosen_effect']);
            $authorization = (($mode instanceof RunCancellationMode)
                || HumanRequestService::requiresStepUp($validated['chosen_effect']))
                ? InterventionAuthorization::consumeFresh(
                    $request,
                    $user,
                    $stepUp,
                    self::STEP_UP_ACTION,
                    [$humanRequest->run_id, $humanRequest->id, (int) $validated['run_version'], $validated['chosen_effect']],
                )
                : null;
            if ($mode instanceof RunCancellationMode) {
                $cancellations->request(
                    $humanRequest,
                    $user,
                    (int) $validated['run_version'],
                    $mode,
                    (string) ($validated['reason'] ?? ''),
                    $authorization,
                );
            } else {
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
                    $authorization,
                    $validated['finding_id'] ?? null,
                    $validated['finding_disposition'] ?? null,
                    $validated['disposition_reason'] ?? null,
                );
            }
        } catch (StepUpRequiredException) {
            // The missing proof is a form-level condition of the panel flow,
            // not a generic authorization failure: the answer returns to the
            // panel so the step-up form there can be used first.
            return back()->withErrors(['chosen_effect' => 'Diese Wirkung verlangt eine frische Step-up-Bestätigung. Bitte zuerst das Step-up-Formular bestätigen.']);
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
            'strong_authorization_required', 'step_up_required' => 'Diese Wirkung verlangt eine Approverrolle und frische starke Anmeldung.',
            'reason_required' => 'Für Abbruch oder Blockierung ist eine Begründung erforderlich.',
            'cancel_after_push_forbidden' => 'Nach bestätigter Branchveröffentlichung ist nur noch die Statussynchronisation zulässig.',
            'attention_user_unavailable' => 'Es ist kein aktiver Attention-User gebunden.',
            'checkpoint_not_bound' => 'Der Run besitzt keinen gebundenen Checkpoint.',
            'bound_step_not_parkable' => 'Der gebundene Schritt konnte nicht geparkt werden.',
            default => 'Die Antwort wurde ohne Wirkung abgewiesen.',
        };
    }
}
