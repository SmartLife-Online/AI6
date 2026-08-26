<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>Human Request</h1>
        <p><a href="{{ route('human-requests.index') }}">Zurück zur Attention-Inbox</a></p>
    </header>

    @if ($errors->any())
        <section class="ai6-errors" aria-label="Antwort abgewiesen">
            <h2>Antwort nicht angenommen</h2>
            <p>{{ $errors->first() }}</p>
        </section>
    @endif

    @if (session('status'))
        <p>{{ session('status') }}</p>
    @endif

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
        @php($stepUpNeeded = $cancellationActions !== []
            || $reportOnlyEffects !== []
            || collect($humanRequest->allowed_effects)->contains(
                static fn (string $effect): bool => \App\AI6\HumanLoop\HumanRequestService::requiresStepUp($effect),
            ))
        @if ($stepUpNeeded)
            <section aria-label="Step-up">
                <h2>Frischer Step-up für Interventionen</h2>
                <p>Blockierung, Hard-Cancel und limitwirksame Interventionen verlangen eine frische Step-up-Bestätigung.</p>
                <form method="POST" action="{{ route('auth.step-up.totp.verify', ['action' => \App\AI6\HumanLoop\Http\HumanRequestAnswerController::STEP_UP_ACTION]) }}">
                    @csrf
                    <label for="intervention_step_up_code">TOTP-Code für Step-up</label>
                    <input id="intervention_step_up_code" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}">
                    <button type="submit">Step-up bestätigen</button>
                </form>
            </section>
        @endif
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
                @if (in_array($effect, $reportOnlyEffects, true) || ! in_array($effect, ['refresh_expected_oid', 'finding_disposition', 'controlled_abort'], true))
                    <button type="submit" name="chosen_effect" value="{{ $effect }}">{{ $effectLabels[$effect] ?? $effect }}</button>
                @endif
            @endforeach
            @if (in_array('controlled_abort', $humanRequest->allowed_effects, true))
                <p>Die veränderte Control-Basis wird ausschließlich über den kontrollierten Abbruch mit einem der folgenden Wege aufgelöst.</p>
            @endif
            @if (in_array('finding_disposition', $humanRequest->allowed_effects, true))
                @if ($disposableFindings->isNotEmpty())
                    <label for="finding-id">Finding</label>
                    <select id="finding-id" name="finding_id">
                        @foreach ($disposableFindings as $finding)
                            <option value="{{ $finding->id }}">Runde {{ $finding->round_number }}: {{ $finding->title }}</option>
                        @endforeach
                    </select>
                    <label for="finding-disposition">Disposition</label>
                    <select id="finding-disposition" name="finding_disposition">
                        <option value="not_applicable">Nicht zutreffend</option>
                        <option value="accepted_risk">Risiko akzeptieren</option>
                    </select>
                    <label for="disposition-reason">Begründung der Disposition</label>
                    <textarea id="disposition-reason" name="disposition_reason" rows="3" maxlength="2000"></textarea>
                    <button type="submit" name="chosen_effect" value="finding_disposition">Finding disponieren und Schritt fortsetzen</button>
                @else
                    <p>Für diesen Stand ist kein disponierbares Finding vorhanden.</p>
                @endif
            @endif
            @if (in_array('refresh_expected_oid', $humanRequest->allowed_effects, true) && ! in_array('refresh_expected_oid', $reportOnlyEffects, true))
                <p>Nach Aktualisierung der erwarteten OID muss die Statusentscheidung mit einem der folgenden Wege erneut autorisiert werden.</p>
            @endif
            @if ($cancellationActions !== [])
                <label for="intervention-reason">Begründung für Abbruch oder Blockierung</label>
                <textarea id="intervention-reason" name="reason" rows="3" maxlength="2000"></textarea>
                @foreach ($cancellationActions as $effect => $label)
                    <button type="submit" name="chosen_effect" value="{{ $effect }}">{{ $label }}</button>
                @endforeach
            @endif
        </form>
    @else
        <p>Diese Anfrage ist bereits aufgelöst.</p>
    @endif
</div>
