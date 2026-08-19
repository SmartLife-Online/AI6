<div class="ai6-run-timeline" wire:poll.2s>
    <header class="ai6-page-header">
        <h1>Run-Timeline</h1>
        <p><a href="{{ route('projects.show', $project) }}">Zurück zum Projekt</a></p>
    </header>

    <dl>
        <dt>Run</dt><dd><code>{{ $run->id }}</code></dd>
        <dt>Zustand</dt><dd data-run-state="{{ $run->state->value }}">{{ $run->state->value }}</dd>
        <dt>Phase</dt><dd data-run-phase="{{ $run->phase->value }}">{{ $run->phase->value }}</dd>
        <dt>Wartestatus</dt><dd data-run-wait="{{ $run->wait_reason?->value ?? '' }}">{{ $run->wait_reason?->value ?? '–' }}</dd>
    </dl>

    <h2>Schritte</h2>
    <ul>
        @forelse ($jobs as $job)
            <li data-step-type="{{ $job->step_type }}" data-step-state="{{ $job->state->value }}">
                <strong>{{ $job->step_type }}</strong>: {{ $job->state->value }}
                @if ($job->failure_code !== null)
                    (<code>{{ $job->failure_code }}</code>)
                @endif
            </li>
        @empty
            <li>Noch kein Schritt geplant.</li>
        @endforelse
    </ul>

    <h2>Sessions</h2>
    <ul>
        @forelse ($sessions as $session)
            <li data-session-role="{{ $session->role }}" data-session-state="{{ $session->session_id === null ? 'unbound' : 'bound' }}">
                <strong>{{ $session->role }}</strong>:
                {{ $session->provider_profile }} · {{ $session->model }} · {{ $session->effort }}
                ({{ $session->session_id === null ? 'noch keine Sitzung' : 'Sitzung gebunden' }})
            </li>
        @empty
            <li>Noch keine Session.</li>
        @endforelse
    </ul>

    <h2>Wirksamer Scope</h2>
    <p data-scope-limit-used="{{ $addedScopePathsUsed }}" data-scope-limit-max="{{ $addedScopePathsLimit ?? '' }}">
        Verbrauchte Zusatzpfade: {{ $addedScopePathsUsed }} von {{ $addedScopePathsLimit ?? '–' }}
    </p>
    <h3>Initialer Scope</h3>
    <ul>
        @forelse ($initialScope as $path)
            <li data-initial-scope-path="{{ $path }}"><code>{{ $path }}</code></li>
        @empty
            <li>Kein initialer Scope gebunden.</li>
        @endforelse
    </ul>
    <h3>Scope-Entscheidungen</h3>
    <ul>
        @forelse ($scopeDecisions as $decision)
            <li data-scope-decision-path="{{ $decision['path'] }}" data-scope-decision-outcome="{{ $decision['outcome'] }}" data-scope-decision-reason="{{ $decision['reason'] }}">
                <code>{{ $decision['path'] }}</code>
                ({{ $decision['outcome'] === 'approved' ? 'genehmigt' : 'abgelehnt' }},
                {{ $scopeDecisionReasons[$decision['reason']] ?? 'unbekannter Grund' }})
            </li>
        @empty
            <li>Noch keine Scope-Erweiterung.</li>
        @endforelse
    </ul>
    <h3>Quarantänierte Pfade</h3>
    <ul>
        @forelse ($quarantinedPaths as $entry)
            <li data-quarantined-path="{{ $entry['path'] }}" data-quarantined-change="{{ $entry['change'] }}">
                <code>{{ $entry['path'] }}</code> ({{ $entry['change'] }})
            </li>
        @empty
            <li>Keine quarantänierten Pfade.</li>
        @endforelse
    </ul>

    <h2>Geänderte Dateien</h2>
    <ul>
        @forelse ($changedFiles as $change)
            <li data-changed-path="{{ $change['path'] ?? '' }}" data-change-type="{{ $change['change'] ?? '' }}">
                <code>{{ $change['path'] ?? '' }}</code>
                ({{ $change['change'] ?? '' }})
            </li>
        @empty
            <li>Noch keine geänderten Dateien.</li>
        @endforelse
    </ul>

    <h2>Entscheidungen</h2>
    <ul>
        @forelse ($decisions as $decision)
            <li data-decision-key="{{ $decision['key'] ?? '' }}">
                <strong>{{ $decision['title'] ?? '' }}</strong>
                — {{ $decision['rationale'] ?? '' }}
            </li>
        @empty
            <li>Noch keine Entscheidungen.</li>
        @endforelse
    </ul>

    <h2>Timeline</h2>
    <ol>
        @forelse ($events as $event)
            <li>
                <time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('Y-m-d H:i:s') }}</time>
                <strong>{{ $event->event_type }}</strong> – {{ $event->redacted_payload }}
            </li>
        @empty
            <li>Noch keine Ereignisse.</li>
        @endforelse
    </ol>
</div>
