<div class="ai6-approval">
    <header><h1>Approval-Queue und Startberechtigung</h1></header>
    <dl>
        <dt>Ticket</dt><dd>{{ $approval->ticket_id }}</dd>
        <dt>Queuezustand</dt><dd>{{ $approval->queue_state }}</dd>
        <dt>Sagaphase</dt><dd>{{ $approval->saga_phase }}</dd>
        <dt>Geprüfte Todo-Bindung</dt><dd><code>{{ $approval->reviewed_ticket_blob_sha }}</code> auf <code>{{ $approval->reviewed_control_sha }}</code></dd>
        <dt>Freigegebene Ready-Bindung</dt><dd>@if ($approval->approved_ticket_blob_sha !== null)<code>{{ $approval->approved_ticket_blob_sha }}</code> auf <code>{{ $approval->approved_control_sha }}</code>@else Noch nicht bestätigt @endif</dd>
        <dt>Aktuelle Startberechtigung</dt><dd>{{ $eligibility['eligible'] ? 'Startbar' : 'Nicht startbar' }}</dd>
    </dl>
    <button type="button" wire:click="requestEvaluation" wire:loading.attr="disabled">Startberechtigung asynchron prüfen</button>
    @if ($evaluationState === 'queued')
        <p wire:poll.2s>Die Prüfung läuft im Worker.</p>
    @elseif ($evaluationState === 'ready')
        <p>Die Prüfung ist abgeschlossen.</p>
    @elseif ($evaluationState === 'conflict')
        <p>Die Prüfung endete mit einem Konflikt.</p>
    @endif
    @if ($eligibility['reasons'] !== [])
        <h2>Gründe</h2>
        <ul>@foreach ($eligibility['reasons'] as $reason)<li><code>{{ $reason }}</code></li>@endforeach</ul>
    @endif
    @if ($readModel !== null)<p><a href="{{ route('projects.tickets.show', [$project, $readModel]) }}">Zum Ticket</a></p>@endif
</div>
