@extends('layouts.app')

@section('title', 'Projekte – AI6')

@section('content')
    <h1>Projekte</h1>

    @can('create', \App\AI6\Projects\Models\Project::class)
        <p><a href="{{ route('projects.create') }}">Projekt registrieren</a></p>
    @endcan

    @if ($projects->isEmpty())
        <p>Keine Projekte verfügbar.</p>
    @else
        <ul>
            @foreach ($projects as $project)
                <li>
                    <a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                    <dl>
                        <dt>Remote</dt>
                        <dd>{{ $project->remote ?? 'Nicht registriert' }}</dd>
                        <dt>Control-Branch</dt>
                        <dd>{{ $project->control_branch ?? 'Nicht registriert' }}</dd>
                        <dt>Projektkennung</dt>
                        <dd>{{ $project->project_identifier ?? 'Nicht registriert' }}</dd>
                        <dt>Provisionierungszustand</dt>
                        <dd>{{ $project->provisioning_status->value }}</dd>
                    </dl>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
