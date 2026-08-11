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
        <dt>Ausstehender Control-Ref</dt>
        <dd>{{ $project->pending_control_ref ?? 'Keine ausstehende Bindung' }}</dd>
        <dt>Ausstehende Control-OID</dt>
        <dd>{{ $project->pending_control_oid ?? 'Keine ausstehende Bindung' }}</dd>
        <dt>Quelloperation der ausstehenden Bindung</dt>
        <dd>{{ $project->pending_control_operation_id ?? 'Keine ausstehende Bindung' }}</dd>
        <dt>Control-Bindungsversion</dt>
        <dd>{{ $project->control_binding_version }}</dd>
        <dt>Control-Generation</dt>
        <dd>{{ $project->control_generation }}</dd>
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

    @can('refreshReadModel', $project)
        @if ($project->provisioning_status->value === 'provisioned' && $project->control_oid !== null && $project->pending_control_oid === null)
            <h2>Read Model aktualisieren</h2>
            <p>Der Server akzeptiert genau einen Pfad innerhalb von <code>{{ $refreshBasePath }}</code>.</p>
            <form method="POST" action="{{ route('projects.ticket-read-model.refresh', $project) }}">
                @csrf
                <input type="hidden" name="operation_id" value="{{ $refreshOperationId }}">
                <label for="relative_path">Repositoryrelativer Kandidatenpfad</label>
                <input id="relative_path" name="relative_path" required maxlength="1024" placeholder="{{ $refreshBasePath }}/…" value="{{ old('relative_path') }}">
                <button type="submit">Refresh beauftragen</button>
            </form>
        @endif
    @endcan

    <h2>Blobgebundenes Read Model</h2>
    @if ($readModels !== [])
        @foreach ($readModels as $readModelStatus)
        @php($readModel = $readModelStatus['readModel'])
        <dl>
            <dt>Pfad</dt>
            <dd>{{ $readModel->relative_path }}</dd>
            <dt>Control-Commit</dt>
            <dd>{{ $readModel->control_commit }}</dd>
            <dt>Blob-SHA</dt>
            <dd>{{ $readModel->blob_sha }}</dd>
            <dt>Aktualisiert</dt>
            <dd>{{ $readModel->generated_at->toIso8601String() }}</dd>
            <dt>Dokumentzustand</dt>
            <dd>{{ $readModel->document_state->value }}</dd>
            <dt>Validierungsprofil</dt>
            <dd>{{ $readModelStatus['validationProfile'] ?? 'Nicht profiliert' }}</dd>
            <dt>Redactionzustand</dt>
            <dd>{{ $readModel->redaction_state->value }}</dd>
            <dt>Aktualität</dt>
            <dd>{{ $readModelStatus['isStale'] ? 'Veraltet' : 'Aktuell' }}</dd>
            <dt>Staleness-Prädikate</dt>
            <dd>{{ $readModelStatus['staleReasons'] === [] ? 'Keine' : implode(', ', $readModelStatus['staleReasons']) }}</dd>
            <dt>Editorquelle</dt>
            <dd>{{ $readModelStatus['editorEligible'] ? 'Zulässig' : 'Fail-closed gesperrt' }}</dd>
            <dt>Approvalquelle</dt>
            <dd>{{ $readModelStatus['approvalEligible'] ? 'Zulässig' : 'Fail-closed gesperrt' }}</dd>
        </dl>
        @endforeach
    @else
        <p>Noch kein Read Model veröffentlicht.</p>
    @endif

    @if ($latestRefresh !== null)
        <p>
            Letzter Read-Model-Refresh:
            <a href="{{ route('projects.operations.show', [$project, $latestRefresh]) }}">
                {{ $latestRefresh->state->value }} / {{ $latestRefresh->phase->value }}
            </a>
        </p>
    @endif

    @can('synchronizeManagedClone', $project)
        @if ($project->provisioning_status->value === 'provisioned' && $project->control_oid === null && $project->pending_control_oid === null)
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

    @can('changeControlBranch', $project)
        @if ($project->provisioning_status->value === 'provisioned' && ($project->control_oid !== null || $project->pending_control_oid !== null))
            <h2>Control-Branch wechseln</h2>
            <p>Der Wechsel erfordert eine frische Step-up-Bestätigung für <code>control_branch.change</code>.</p>
            <form method="POST" action="{{ route('auth.step-up.totp.verify', ['action' => 'control_branch.change']) }}">
                @csrf
                <label for="control_branch_step_up_code">TOTP-Code für Step-up</label>
                <input id="control_branch_step_up_code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}">
                <button type="submit">Step-up bestätigen</button>
            </form>
            <form method="POST" action="{{ route('projects.control-branch.change', $project) }}">
                @csrf
                <input type="hidden" name="operation_id" value="{{ $branchOperationId }}">
                <label for="new_control_ref">Neuer vollständiger Control-Ref</label>
                <input id="new_control_ref" name="new_control_ref" required maxlength="255" value="{{ old('new_control_ref') }}">
                <button type="submit">Branchwechsel beauftragen</button>
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
