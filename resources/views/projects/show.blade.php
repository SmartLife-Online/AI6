@extends('layouts.app')

@section('title', $project->name.' – AI6')

@section('content')
    <h1>{{ $project->name }}</h1>
    <p>Projekt-ID: {{ $project->getKey() }}</p>

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

    @if ($publicDeployKey !== null)
        <h2>Öffentlicher Deploy-Key</h2>
        <pre>{{ $publicDeployKey }}</pre>
    @endif
@endsection
