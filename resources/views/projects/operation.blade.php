@extends('layouts.app')

@section('title', 'Control-Operation – AI6')

@section('content')
    <h1>Control-Operation</h1>
    <p><a href="{{ route('projects.show', $project) }}">Zurück zu {{ $project->name }}</a></p>

    <dl>
        <dt>Operations-ID</dt>
        <dd>{{ $operation->id }}</dd>
        <dt>Typ</dt>
        <dd>{{ $operation->operation_type->value }}</dd>
        <dt>Zustand</dt>
        <dd>{{ $operation->state->value }}</dd>
        <dt>Phase</dt>
        <dd>{{ $operation->phase->value }}</dd>
        <dt>Versuche</dt>
        <dd>{{ $operation->attempts }}</dd>
        @if ($operation->target_control_oid !== null)
            <dt>Ziel-Control-OID</dt>
            <dd>{{ $operation->target_control_oid }}</dd>
        @endif
    </dl>

    @if ($operation->result !== null)
        <p>Ergebnis: {{ $operation->result->outcome->value }}</p>
        <p>Zusammenfassung: {{ $operation->result->safe_summary }}</p>
    @endif

    @if ($operation->last_error !== null)
        <p>Letzter Fehler: {{ $operation->last_error }}</p>
    @endif

    @if ($operation->state->value === 'recovery_required')
        <h2>Recovery-Entscheidung erforderlich</h2>
        <p>{{ $operation->finding_text }}</p>

        @can('decideRecovery', $project)
            <p>Diese Aktion erfordert eine frische Step-up-Bestätigung.</p>
            <form method="POST" action="{{ route('projects.operations.recover', [$project, $operation]) }}">
                @csrf
                <input type="hidden" name="expected_attempt_token" value="{{ $operation->recovery_attempt_token }}">
                <input type="hidden" name="expected_operation_version" value="{{ $operation->recovery_version }}">
                <input type="hidden" name="finding_hash" value="{{ $operation->finding_hash }}">

                <label for="decision">Entscheidung</label>
                <select id="decision" name="decision" required>
                    <option value="retry_reconciliation">Reconciliation erneut versuchen</option>
                    <option value="adopt_external_state">Externen Zustand übernehmen</option>
                    <option value="abandon_operation">Operation aufgeben</option>
                </select>

                <label for="reason">Begründung (bei Aufgabe erforderlich)</label>
                <textarea id="reason" name="reason"></textarea>
                <fieldset>
                    <legend>Gebundene Evidenz (bei Aufgabe erforderlich)</legend>
                    <p>Entweder einen geprüften Commit oder Base-Commit und SHA-256 des vollständigen Prüfdiffs angeben.</p>
                    <label for="evidence_commit">Geprüfter Git-Commit</label>
                    <input id="evidence_commit" name="evidence_commit" autocomplete="off">
                    <label for="evidence_base_commit">Base-Commit</label>
                    <input id="evidence_base_commit" name="evidence_base_commit" autocomplete="off">
                    <label for="evidence_diff_sha256">SHA-256 des vollständigen Prüfdiffs</label>
                    <input id="evidence_diff_sha256" name="evidence_diff_sha256" autocomplete="off">
                </fieldset>
                <button type="submit">Recovery-Entscheidung speichern</button>
            </form>
        @endcan
    @endif

    @if ($operation->recoveryDecisions->isNotEmpty())
        <h2>Bisherige Recovery-Entscheidungen</h2>
        <ul>
            @foreach ($operation->recoveryDecisions as $recoveryDecision)
                <li>
                    {{ $recoveryDecision->created_at?->toIso8601String() }} –
                    {{ $recoveryDecision->actor->name }} –
                    {{ $recoveryDecision->decision->value }} –
                    {{ $recoveryDecision->state }} –
                    Befund {{ $recoveryDecision->finding_hash }}
                    @if ($recoveryDecision->reason !== null)
                        – {{ $recoveryDecision->reason }}
                    @endif
                    @if ($recoveryDecision->bound_evidence !== null)
                        – Evidenz: {{ $recoveryDecision->bound_evidence }}
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
