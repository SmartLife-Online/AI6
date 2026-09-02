@extends('layouts.app')

@section('title', 'Projektqueue – '.$project->name.' – AI6')

@section('content')
    <h1>Projektqueue: {{ $project->name }}</h1>
    <p><a href="{{ route('projects.show', $project) }}">Zurück zum Projekt</a></p>

    @if (session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    @error('queue')
        <p role="alert">{{ $message }}</p>
    @enderror

    <section aria-labelledby="next-startable-heading">
        <h2 id="next-startable-heading">Nächstes startbares Ticket</h2>
        <p>{{ $nextStartable?->ticket_id ?? 'Derzeit ist kein eingereihtes Ticket startbar.' }}</p>
    </section>

    <section aria-labelledby="queue-entries-heading">
        <h2 id="queue-entries-heading">Einträge</h2>
        @forelse ($entries as $entry)
            @php($approval = $entry['approval'])
            <article class="ai6-ticket-card">
                <h3>{{ $entry['position'] }}. {{ $approval->ticket_id }}</h3>
                <dl>
                    <dt>Zustand</dt>
                    <dd>{{ $approval->queue_state }}</dd>
                    <dt>Eingereiht am</dt>
                    <dd>{{ $approval->queued_at?->toIso8601String() ?? 'Nicht eingereiht' }}</dd>
                    <dt>Startbarkeit</dt>
                    <dd>{{ $entry['eligible'] ? 'Startbar' : 'Blockiert' }}</dd>
                </dl>

                <h4>Blockierende Gründe</h4>
                @if ($entry['reasons'] === [])
                    <p>Keine.</p>
                @else
                    <ul>
                        @foreach ($entry['reasons'] as $reason)
                            <li><code>{{ $reason }}</code></li>
                        @endforeach
                    </ul>
                @endif

                @can('startRun', $project)
                    @if (in_array($approval->queue_state, ['available', 'queued'], true))
                        <form method="POST" action="{{ route('projects.queue.enqueue', [$project, $approval]) }}">
                            @csrf
                            <input type="hidden" name="expected_version" value="{{ $approval->version }}">
                            <button type="submit">{{ $approval->queue_state === 'queued' ? 'Ans Ende stellen' : 'Einreihen' }}</button>
                        </form>
                    @endif
                    @if ($approval->queue_state === 'queued')
                        <form method="POST" action="{{ route('projects.queue.remove', [$project, $approval]) }}">
                            @csrf
                            <input type="hidden" name="expected_version" value="{{ $approval->version }}">
                            <button type="submit">Entfernen</button>
                        </form>
                        <form method="POST" action="{{ route('projects.approvals.start', [$project, $approval]) }}">
                            @csrf
                            <button type="submit" @disabled(! $entry['eligible'])>Run starten</button>
                        </form>
                    @endif
                @endcan
            </article>
        @empty
            <p>Es gibt noch keine freigegebenen Approvals.</p>
        @endforelse
    </section>
@endsection
