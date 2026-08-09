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
        <dt>Aktive Control-OID</dt>
        <dd>{{ $project->control_oid ?? 'Noch nicht gebunden' }}</dd>
        <dt>Control-Bindungsversion</dt>
        <dd>{{ $project->control_binding_version }}</dd>
        <dt>Letzte Aktualisierung</dt>
        <dd>{{ $controlUpdatedAt?->toIso8601String() ?? 'Noch nicht aktualisiert' }}</dd>
        <dt>Aktualität</dt>
        <dd>{{ $controlIsStale ? 'Veraltet' : 'Aktuell' }}</dd>
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

    @can('synchronizeManagedClone', $project)
        @if ($project->provisioning_status->value === 'provisioned' && $project->control_oid === null)
            <h2>Managed-Clone erstellen</h2>
            <form method="POST" action="{{ route('projects.managed-clone.clone', $project) }}">
                @csrf
                <input type="hidden" name="operation_id" value="{{ $cloneOperationId }}">
                <button type="submit">Clone starten</button>
            </form>
        @elseif ($project->provisioning_status->value === 'provisioned')
            <h2>Managed-Clone aktualisieren</h2>
            <form method="POST" action="{{ route('projects.managed-clone.fetch', $project) }}">
                @csrf
                <input type="hidden" name="operation_id" value="{{ $fetchOperationId }}">
                <button type="submit">Fetch starten</button>
            </form>
        @endif
    @endcan

    @if ($latestSynchronization?->state === \App\AI6\Git\ControlOperationState::RECOVERY_REQUIRED)
        <p>Die Managed-Clone-Operation wartet auf eine Recovery-Entscheidung.</p>
    @endif

    @if ($latestSynchronization !== null)
        <p>
            Letzte Managed-Clone-Synchronisierung:
            <a href="{{ route('projects.operations.show', [$project, $latestSynchronization]) }}">
                {{ $latestSynchronization->state->value }} / {{ $latestSynchronization->phase->value }}
            </a>
            @if ($latestSynchronization->result !== null)
                – Ergebnis: {{ $latestSynchronization->result->outcome->value }}
                – {{ $latestSynchronization->result->safe_summary }}
            @endif
            @if ($latestSynchronization->last_error !== null)
                – Letzter Fehler: {{ $latestSynchronization->last_error }}
            @endif
        </p>
    @endif

    @if ($latestOperation !== null && $latestOperation->id !== $latestSynchronization?->id)
        <p>
            Letzte Control-Operation:
            <a href="{{ route('projects.operations.show', [$project, $latestOperation]) }}">
                {{ $latestOperation->state->value }} / {{ $latestOperation->phase->value }}
            </a>
        </p>
    @endif
@endsection
