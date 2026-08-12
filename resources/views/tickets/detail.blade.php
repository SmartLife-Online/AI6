<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>
            {{ $viewModel->ticketId ?? $viewModel->readModel->relative_path }}@if ($viewModel->title !== null) – {{ $viewModel->title }}@endif
        </h1>
        <p><a href="{{ route('projects.tickets.index', $project) }}">Zurück zur Ticketübersicht</a></p>
    </header>

    @if ($viewModel->readModel->redaction_state === \App\AI6\Projects\TicketReadModelRedactionState::CONTENT_REDACTED)
        <p class="ai6-masked-note">
            Der Inhalt dieser Projektion ist maskiert. Sie wird niemals als vollständig geprüfter
            Vertrag dargestellt und besitzt weder Approval- noch Edit-Aktion.
        </p>
    @endif

    @if ($viewModel->document !== null)
        <dl class="ai6-required-fields">
            <div class="ai6-required-field">
                <dt>id</dt>
                <dd class="ai6-strong">{{ $viewModel->ticketId ?? 'Nicht lesbar' }}</dd>
            </div>
            <div class="ai6-required-field">
                <dt>title</dt>
                <dd class="ai6-strong">{{ $viewModel->title ?? 'Nicht lesbar' }}</dd>
            </div>
            <div class="ai6-required-field">
                <dt>status</dt>
                <dd class="ai6-strong">{{ $viewModel->ticketStatus ?? 'Nicht lesbar' }}</dd>
            </div>
            <div class="ai6-required-field ai6-required-wide">
                <dt>depends_on</dt>
                <dd>@include('tickets.partials.dependency-badges', ['badges' => $badges])</dd>
            </div>
            <div class="ai6-required-field ai6-required-wide">
                <dt>Goal</dt>
                <dd>{{ $viewModel->goal ?? 'Kein Goal-Abschnitt lesbar' }}</dd>
            </div>
        </dl>
    @elseif ($viewModel->readModel->document_state === \App\AI6\Projects\TicketDocumentState::UNPARSED)
        <p class="ai6-envelope-note">
            Diese Projektion ist noch nicht geparst. Sichtbar sind ausschließlich der Envelope-
            und der Refreshzustand; eine inhaltliche Gültigkeitsaussage existiert nicht.
        </p>
    @else
        <p class="ai6-envelope-note">
            Der redigierte Inhalt ist nicht als V1-Ticket lesbar; die strukturierten Fehler
            stehen unten.
        </p>
    @endif

    @include('tickets.partials.read-model-state', ['viewModel' => $viewModel, 'project' => $project])

    <div class="ai6-ticket-actions">
        @include('tickets.partials.entry-actions', ['viewModel' => $viewModel])
        @include('tickets.partials.refresh', [
            'project' => $project,
            'viewModel' => $viewModel,
            'operationId' => $refreshOperationId,
        ])
    </div>

    @if ($viewModel->readModel->document_state === \App\AI6\Projects\TicketDocumentState::INVALID)
        <section class="ai6-errors" aria-label="Validierungsfehler">
            <h2>Strukturierte Validierungsfehler</h2>
            <table class="ai6-error-table">
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Feld</th>
                        <th scope="col">Meldung</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($viewModel->readModel->validation_errors as $validationError)
                        <tr>
                            <td>{{ $validationError['code'] }}</td>
                            <td>{{ $validationError['field'] }}</td>
                            <td>{{ $validationError['message'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($renderedBody !== null)
        <section class="ai6-markdown" aria-label="Ticketinhalt">
            {!! $renderedBody !!}
        </section>
    @elseif ($unrenderableSource !== null)
        <section aria-label="Redigierter Rohinhalt">
            <h2>Redigierter Rohinhalt</h2>
            <pre class="ai6-source">{{ $unrenderableSource }}</pre>
        </section>
    @endif
</div>
