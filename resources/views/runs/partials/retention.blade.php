@if ($retention['deleted_at'] !== null)
    <span class="ai6-muted" data-retention-deleted="{{ $retention['category'] }}">{{ $what }} am <time datetime="{{ $retention['deleted_at']->toIso8601String() }}">{{ $retention['deleted_at']->format('Y-m-d H:i:s') }}</time> nach Ablauf der Aufbewahrung ({{ $retention['category'] }}, Ablauf <time datetime="{{ $retention['expires_at']?->toIso8601String() }}">{{ $retention['expires_at']?->format('Y-m-d') }}</time>) durch den Retentionlauf gelöscht; Tombstone-Herkunft: {{ $retention['category'] }}, Fingerprint-Key <code>{{ $retention['fingerprint_key_id'] ?? '–' }}</code> Version {{ $retention['fingerprint_version'] ?? '–' }}.</span>
@elseif ($retention['unbound'])
    <span class="ai6-muted" data-retention-unbound="{{ $retention['category'] }}">Aufbewahrung ({{ $retention['category'] }}) ist für diesen Datensatz nicht gebunden; {{ $what }} werden nicht ausgegeben.</span>
@elseif ($retention['expired'])
    <span class="ai6-muted" data-retention-expired="{{ $retention['category'] }}">Aufbewahrung ({{ $retention['category'] }}) am <time datetime="{{ $retention['expires_at']->toIso8601String() }}">{{ $retention['expires_at']->format('Y-m-d') }}</time> abgelaufen; {{ $what }} werden nicht mehr ausgegeben und im nächsten Retentionlauf gelöscht.</span>
@elseif ($retention['expires_at'] !== null)
    <span class="ai6-muted" data-retention-remaining="{{ $retention['remaining_days'] }}" data-retention-category="{{ $retention['category'] }}">Verbleibende Aufbewahrung ({{ $retention['category'] }}): {{ $retention['remaining_days'] }} Tage bis <time datetime="{{ $retention['expires_at']->toIso8601String() }}">{{ $retention['expires_at']->format('Y-m-d') }}</time>.</span>
@endif
