<div class="ai6-tickets">
    <header class="ai6-page-header">
        <h1>Attention-Inbox</h1>
        <p>Offene Fragen und Freigaben aus berechtigten Projekten.</p>
    </header>

    @if ($requests->isEmpty())
        <p>Keine offenen Anfragen.</p>
    @else
        <ul class="ai6-ticket-list">
            @foreach ($requests as $row)
                @php($humanRequest = $row['request'])
                <li class="ai6-ticket-card" wire:key="{{ $humanRequest->id }}">
                    <div class="ai6-ticket-head">
                        <a class="ai6-ticket-link" href="{{ route('projects.human-requests.show', [$humanRequest->project_id, $humanRequest->id]) }}">
                            <span class="ai6-ticket-id">{{ $row['ticket'] !== '' ? $row['ticket'] : $humanRequest->id }}</span>
                            <span class="ai6-ticket-title">{{ $humanRequest->title }}</span>
                        </a>
                        <span class="ai6-badge ai6-badge-status">{{ $humanRequest->delivery_status->value }}</span>
                    </div>
                    <p class="ai6-muted">{{ $row['project'] }}</p>
                    <p>Wartegrund: <span data-wait-reason="{{ $row['wait_reason'] }}">{{ $row['wait_reason'] !== '' ? $row['wait_reason'] : '–' }}</span></p>
                    <p>Alter: {{ $humanRequest->created_at?->diffForHumans() }}</p>
                    <p data-delivery-status="{{ $humanRequest->delivery_status->value }}">
                        Zustellstatus: {{ $humanRequest->delivery_status->value }}
                        @if ($humanRequest->delivery_failure_key !== null)
                            ({{ $humanRequest->delivery_failure_key }})
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
