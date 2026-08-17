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
            <li data-session-role="{{ $session->role }}">
                <strong>{{ $session->role }}</strong>:
                {{ $session->provider_profile }} · {{ $session->model }} · {{ $session->effort }}
                ({{ $session->session_id === null ? 'noch keine Sitzung' : 'Sitzung gebunden' }})
            </li>
        @empty
            <li>Noch keine Session.</li>
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
