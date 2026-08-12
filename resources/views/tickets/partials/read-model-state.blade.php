<dl class="ai6-state">
    <div class="ai6-state-item">
        <dt>Dokumentzustand</dt>
        <dd>
            <span class="ai6-badge ai6-badge-state-{{ $viewModel->readModel->document_state->value }}">
                {{ $viewModel->readModel->document_state->value }}
            </span>
            @if ($viewModel->readModel->document_state === \App\AI6\Projects\TicketDocumentState::INVALID && $viewModel->allowsEditor)
                <span class="ai6-badge ai6-badge-repairable">Reparierbar</span>
            @endif
        </dd>
    </div>
    <div class="ai6-state-item">
        <dt>Validierungsprofil</dt>
        <dd>{{ $viewModel->readModel->validation_profile ?? 'Nicht profiliert' }}</dd>
    </div>
    <div class="ai6-state-item">
        <dt>Contract-Hash</dt>
        <dd class="ai6-oid">{{ $viewModel->readModel->ticket_contract_sha256 ?? 'Kein Contract-Hash' }}</dd>
    </div>
    <div class="ai6-state-item">
        <dt>Redaction</dt>
        <dd>
            @if ($viewModel->readModel->redaction_state === \App\AI6\Projects\TicketReadModelRedactionState::CONTENT_REDACTED)
                <span class="ai6-badge ai6-badge-masked">Inhalt maskiert</span>
            @else
                <span class="ai6-badge ai6-badge-clear">Unmaskiert</span>
            @endif
        </dd>
    </div>
    <div class="ai6-state-item">
        <dt>Aktualität</dt>
        <dd>
            @if ($viewModel->isStale)
                <span class="ai6-badge ai6-badge-stale">Veraltet</span>
                <span class="ai6-stale-reasons">Prädikat: {{ implode(', ', $viewModel->staleReasons) }}</span>
            @else
                <span class="ai6-badge ai6-badge-fresh">Aktuell</span>
            @endif
        </dd>
    </div>
    <div class="ai6-state-item">
        <dt>Control-Commit</dt>
        <dd class="ai6-oid">{{ $viewModel->readModel->control_commit }}</dd>
    </div>
    <div class="ai6-state-item">
        <dt>Blob-SHA</dt>
        <dd class="ai6-oid">{{ $viewModel->readModel->blob_sha }}</dd>
    </div>
    <div class="ai6-state-item">
        <dt>Aktualisiert</dt>
        <dd>{{ $viewModel->readModel->generated_at->toIso8601String() }}</dd>
    </div>
    @if ($viewModel->latestRefresh !== null)
        <div class="ai6-state-item">
            <dt>Letzter Refresh-Auftrag</dt>
            <dd>
                <a href="{{ route('projects.operations.show', [$project, $viewModel->latestRefresh]) }}">
                    {{ $viewModel->latestRefresh->state->value }} / {{ $viewModel->latestRefresh->phase->value }}
                </a>
                @if (! $viewModel->latestRefresh->state->terminal())
                    <span class="ai6-badge ai6-badge-running">Auftrag läuft</span>
                @elseif ($viewModel->latestRefresh->state === \App\AI6\Git\ControlOperationState::FAILED)
                    <span class="ai6-badge ai6-badge-error">Auftrag fehlgeschlagen</span>
                    <span class="ai6-muted">Details in der Operationsansicht.</span>
                @endif
            </dd>
        </div>
    @endif
</dl>
