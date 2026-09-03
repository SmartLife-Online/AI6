@if ($excerpt['truncated'])
    <span class="ai6-muted" data-truncated="{{ $what }}"> [Begrenzt: {{ $excerpt['shown'] }} von {{ $excerpt['total'] }} Bytes angezeigt]</span>
@endif
