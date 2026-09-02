<?php

namespace App\AI6\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Policies\ProjectPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class ApprovalQueueController
{
    public function enqueue(
        Request $request,
        Project $project,
        string $approvalId,
        ProjectPolicy $policy,
        ApprovalQueue $queue,
        QueueReevaluation $reevaluation,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User && $policy->startRun($actor, $project), 403);
        $version = $this->expectedVersion($request);

        try {
            $approval = $queue->enqueue($project, $approvalId, $version);
            $reevaluation->scheduleApproval($approval);
        } catch (ApprovalQueueConflict) {
            throw ValidationException::withMessages([
                'queue' => ['Der Queueeintrag wurde zwischenzeitlich geändert. Bitte neu laden.'],
            ]);
        }

        return redirect()->route('projects.queue.index', $project)
            ->with('status', 'Die Approval wurde am Ende der Projektqueue eingereiht.');
    }

    public function remove(
        Request $request,
        Project $project,
        string $approvalId,
        ProjectPolicy $policy,
        ApprovalQueue $queue,
        QueueReevaluation $reevaluation,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User && $policy->startRun($actor, $project), 403);
        $version = $this->expectedVersion($request);

        try {
            $queue->remove($project, $approvalId, $version);
            $reevaluation->scheduleProject($project);
        } catch (ApprovalQueueConflict) {
            throw ValidationException::withMessages([
                'queue' => ['Der Queueeintrag wurde zwischenzeitlich geändert. Bitte neu laden.'],
            ]);
        }

        return redirect()->route('projects.queue.index', $project)
            ->with('status', 'Die Approval wurde aus der Projektqueue entfernt.');
    }

    private function expectedVersion(Request $request): int
    {
        if ($request->except(['_token', 'expected_version']) !== []) {
            throw ValidationException::withMessages(['queue' => ['Die Queueaktion enthält unbekannte Felder.']]);
        }

        return (int) $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];
    }
}
