<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>Tickets – {{ $project->name }}</h1>
        <p><a href="{{ route('projects.show', $project) }}">Zurück zum Projekt</a></p>
    </header>

    <section class="ai6-filters" aria-label="Filter">
        <div class="ai6-filter">
            <label for="ticket-status-filter">Status</label>
            <select id="ticket-status-filter" wire:model.live="status">
                <option value="">Alle</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ $statusOption }}</option>
                @endforeach
            </select>
        </div>
        <div class="ai6-filter">
            <label for="ticket-state-filter">Validierungszustand</label>
            <select id="ticket-state-filter" wire:model.live="state">
                <option value="">Alle</option>
                @foreach ($stateOptions as $stateOption)
                    <option value="{{ $stateOption }}">{{ $stateOption }}</option>
                @endforeach
            </select>
        </div>
        <p class="ai6-filter-count ai6-muted">{{ count($rows) }} von {{ $totalCount }} Projektionen sichtbar</p>
    </section>

    @if ($pendingOperations !== [])
        <section class="ai6-pending-operations" aria-label="Refresh-Aufträge ohne Projektion">
            <h2>Refresh-Aufträge ohne Projektion</h2>
            <ul class="ai6-pending-list">
                @foreach ($pendingOperations as $pendingOperation)
                    <li class="ai6-pending-operation">
                        <span class="ai6-ticket-path">{{ $pendingOperation->getAttribute('refresh_relative_path') }}</span>
                        <a href="{{ route('projects.operations.show', [$project, $pendingOperation]) }}">
                            {{ $pendingOperation->state->value }} / {{ $pendingOperation->phase->value }}
                        </a>
                        @if (! $pendingOperation->state->terminal())
                            <span class="ai6-badge ai6-badge-running">Auftrag läuft</span>
                        @else
                            <span class="ai6-badge ai6-badge-error">Auftrag fehlgeschlagen</span>
                            <span class="ai6-muted">Details in der Operationsansicht.</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($rows === [])
        <p>Keine Ticketprojektion entspricht der aktuellen Auswahl.</p>
    @else
        <ul class="ai6-ticket-list">
            @foreach ($rows as $row)
                @php($viewModel = $row['viewModel'])
                <li class="ai6-ticket-card" wire:key="{{ $viewModel->readModel->getKey() }}">
                    <div class="ai6-ticket-head">
                        <a class="ai6-ticket-link" href="{{ route('projects.tickets.show', [$project, $viewModel->readModel]) }}">
                            <span class="ai6-ticket-id">{{ $viewModel->ticketId ?? $viewModel->readModel->relative_path }}</span>
                            <span class="ai6-ticket-title">{{ $viewModel->title ?? 'Ohne lesbaren Titel' }}</span>
                        </a>
                        @if ($viewModel->ticketStatus !== null)
                            <span class="ai6-badge ai6-badge-status">{{ $viewModel->ticketStatus }}</span>
                        @endif
                    </div>
                    <p class="ai6-ticket-path ai6-muted">{{ $viewModel->readModel->relative_path }}</p>
                    @if ($viewModel->document !== null)
                        <p class="ai6-ticket-goal">{{ $viewModel->goal ?? 'Kein Goal-Abschnitt lesbar' }}</p>
                        @include('tickets.partials.dependency-badges', ['badges' => $row['badges']])
                    @else
                        <p class="ai6-muted">Nur Envelope- und Refreshzustand verfügbar.</p>
                    @endif
                    @include('tickets.partials.read-model-state', ['viewModel' => $viewModel, 'project' => $project])
                    <div class="ai6-ticket-actions">
                        @include('tickets.partials.entry-actions', ['viewModel' => $viewModel])
                        @include('tickets.partials.refresh', [
                            'project' => $project,
                            'viewModel' => $viewModel,
                            'operationId' => $refreshOperationIds[$viewModel->readModel->relative_path],
                        ])
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
