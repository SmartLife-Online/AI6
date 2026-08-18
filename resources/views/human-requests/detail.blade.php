<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>Human Request</h1>
        <p><a href="{{ route('human-requests.index') }}">Zurück zur Attention-Inbox</a></p>
    </header>

    <dl>
        <dt>Ticket</dt><dd>{{ $ticketId !== '' ? $ticketId : '–' }}</dd>
        <dt>Frage</dt><dd>{{ $humanRequest->title }}</dd>
        <dt>Nachricht</dt><dd>{{ $humanRequest->message }}</dd>
        <dt>Begründung</dt><dd>{{ $humanRequest->why_needed }}</dd>
        <dt>Empfehlung</dt><dd>{{ $humanRequest->recommended_option ?? '–' }}</dd>
        <dt>Zustellstatus</dt>
        <dd data-delivery-status="{{ $humanRequest->delivery_status->value }}">
            {{ $humanRequest->delivery_status->value }}
            @if ($humanRequest->delivery_failure_key !== null)
                ({{ $humanRequest->delivery_failure_key }})
            @endif
        </dd>
    </dl>

    <h2>Optionen</h2>
    <ul>
        @forelse ($humanRequest->options as $option)
            <li>{{ $option['key'] }}: {{ $option['label'] }}</li>
        @empty
            <li>Keine Optionen.</li>
        @endforelse
    </ul>

    <h2>Betroffene Pfade</h2>
    <ul>
        @forelse ($humanRequest->affected_paths as $path)
            <li><code>{{ $path }}</code></li>
        @empty
            <li>Keine Pfade.</li>
        @endforelse
    </ul>

    <h2>Bindung</h2>
    <dl>
        <dt>Runversion</dt><dd>{{ $humanRequest->bound_run_version }}</dd>
        <dt>Ticketvertrag</dt><dd><code>{{ $humanRequest->bound_ticket_contract }}</code></dd>
        <dt>Checkpoint</dt><dd><code>{{ $humanRequest->bound_checkpoint }}</code></dd>
        <dt>Scope</dt><dd><code>{{ $humanRequest->bound_scope }}</code></dd>
        <dt>Agentenslot</dt><dd><code>{{ $humanRequest->bound_agent_slot }}</code></dd>
        <dt>Angeforderte Wirkung</dt><dd><code>{{ $humanRequest->bound_requested_effect }}</code></dd>
    </dl>

    @if ($humanRequest->resolution_state->value === 'open')
        <form method="post" action="{{ route('projects.human-requests.answer', [$project, $humanRequest->id]) }}" class="ai6-ticket-actions">
            @csrf
            <input type="hidden" name="run_version" value="{{ $humanRequest->bound_run_version }}">
            <input type="hidden" name="ticket_contract" value="{{ $humanRequest->bound_ticket_contract }}">
            <input type="hidden" name="checkpoint" value="{{ $humanRequest->bound_checkpoint }}">
            <input type="hidden" name="scope" value="{{ $humanRequest->bound_scope }}">
            <input type="hidden" name="agent_slot" value="{{ $humanRequest->bound_agent_slot }}">
            <input type="hidden" name="requested_effect" value="{{ $humanRequest->bound_requested_effect }}">
            @php($effectLabels = collect($humanRequest->options)->pluck('label', 'key'))
            @foreach ($humanRequest->allowed_effects as $effect)
                <button type="submit" name="chosen_effect" value="{{ $effect }}">{{ $effectLabels[$effect] ?? $effect }}</button>
            @endforeach
            <button type="submit" name="chosen_effect" value="cancel">Abbrechen</button>
        </form>
    @else
        <p>Diese Anfrage ist bereits aufgelöst.</p>
    @endif
</div>
