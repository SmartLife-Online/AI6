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

    @can('provisionDeployKey', $project)
        @if (in_array($project->provisioning_status->value, ['not_provisioned', 'provisioning_failed'], true))
            <h2>Deploy-Key provisionieren</h2>
            <form method="POST" action="{{ route('projects.deploy-key.provision', $project) }}">
                @csrf
                <input type="hidden" name="operation_id" value="{{ $provisionOperationId }}">
                <button type="submit">Provisionierung starten</button>
            </form>
        @endif
    @endcan

    @if ($latestOperation !== null)
        <p>
            Letzte Control-Operation:
            <a href="{{ route('projects.operations.show', [$project, $latestOperation]) }}">
                {{ $latestOperation->state->value }} / {{ $latestOperation->phase->value }}
            </a>
        </p>
    @endif
@endsection
