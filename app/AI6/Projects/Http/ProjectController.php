<?php

namespace App\AI6\Projects\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Actions\RegisterProject;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRegistrationRejected;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProjectController
{
    public function index(Request $request, Gate $gate): View
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $projects = Project::all()
            ->sortBy('name')
            ->filter(static fn (Project $project): bool => $gate->forUser($user)->allows('appearInList', $project));

        return view('projects.index', ['projects' => $projects]);
    }

    public function create(): View
    {
        return view('projects.create');
    }

    public function store(Request $request, RegisterProject $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'remote' => ['required', 'string', 'max:2048'],
            'control_branch' => ['required', 'string', 'max:255'],
            'host_key_fingerprint' => ['required', 'string', 'max:255'],
        ]);

        try {
            $project = $action->handle(
                $actor,
                $validated['name'],
                $validated['remote'],
                $validated['control_branch'],
                $validated['host_key_fingerprint'],
            );
        } catch (ProjectRegistrationRejected $exception) {
            throw ValidationException::withMessages([
                $this->validationField($exception->reason) => [$this->validationMessage($exception->reason)],
            ]);
        }

        return redirect()->route('projects.show', $project);
    }

    public function show(Project $project): View
    {
        return view('projects.show', [
            'project' => $project,
            'publicDeployKey' => $project->publicDeployKeyForDisplay(),
            'latestOperation' => $project->controlOperations()
                ->latest('created_at')
                ->first(),
            'provisionOperationId' => (string) Str::uuid(),
        ]);
    }

    private function validationField(string $reason): string
    {
        return match ($reason) {
            'ref_not_allowed' => 'control_branch',
            'format_error', 'digest_mismatch' => 'host_key_fingerprint',
            default => 'remote',
        };
    }

    private function validationMessage(string $reason): string
    {
        return match ($reason) {
            'protocol_not_allowed' => 'Das Remote-Protokoll ist nicht erlaubt.',
            'host_not_allowed' => 'Der Remote-Host ist nicht erlaubt.',
            'path_not_allowed' => 'Der Remote-Pfad ist nicht erlaubt.',
            'ref_not_allowed' => 'Der Control-Branch ist nicht erlaubt.',
            'ssh_remote_invalid' => 'Das SSH-Remote ist ungültig.',
            'format_error', 'digest_mismatch' => 'Der Hostkey-Fingerprint wurde abgelehnt.',
            default => 'Die Projektmetadaten wurden abgelehnt.',
        };
    }
}
