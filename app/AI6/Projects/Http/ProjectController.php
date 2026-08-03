<?php

namespace App\AI6\Projects\Http;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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

    public function show(Project $project): View
    {
        return view('projects.show', ['project' => $project]);
    }
}
