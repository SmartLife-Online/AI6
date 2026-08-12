<ul class="ai6-dependency-badges" aria-label="Abhängigkeiten">
    @forelse ($badges as $badge)
        <li>
            @if ($badge->kind === \App\AI6\Tickets\DependencyBadge::MISSING)
                <span class="ai6-badge ai6-badge-error ai6-dependency-badge">{{ $badge->ticketId }} – fehlt im Ticketbestand</span>
            @elseif ($badge->kind === \App\AI6\Tickets\DependencyBadge::UNKNOWN)
                <span class="ai6-badge ai6-badge-error ai6-dependency-badge">{{ $badge->ticketId }} – Status unbekannt</span>
            @elseif ($badge->kind === \App\AI6\Tickets\DependencyBadge::UNREADABLE)
                <span class="ai6-badge ai6-badge-error ai6-dependency-badge">{{ $badge->ticketId }} – vorhanden, aber nicht lesbar</span>
            @elseif ($badge->kind === \App\AI6\Tickets\DependencyBadge::AMBIGUOUS)
                <span class="ai6-badge ai6-badge-error ai6-dependency-badge">{{ $badge->ticketId }} – mehrdeutig im Ticketbestand</span>
            @elseif ($badge->kind === \App\AI6\Tickets\DependencyBadge::SATISFIED)
                <span class="ai6-badge ai6-badge-satisfied ai6-dependency-badge">{{ $badge->ticketId }} – {{ $badge->targetStatus }}</span>
            @else
                <span class="ai6-badge ai6-badge-open ai6-dependency-badge">{{ $badge->ticketId }} – {{ $badge->targetStatus }}</span>
            @endif
        </li>
    @empty
        <li><span class="ai6-muted">Keine Abhängigkeiten deklariert</span></li>
    @endforelse
</ul>
