@extends('layouts.app')

@section('title', 'Anmeldung bestätigen – AI6')

@section('content')
    <h1>Anmeldung bestätigen</h1>

    @if ($confirmation?->failure_key === 'recipient_unavailable')
        <p role="alert">Keine gültige Bestätigungsadresse ist konfiguriert. Die Sitzung bleibt gesperrt.</p>
    @elseif ($confirmation?->failure_key === 'mail_transport_not_deliverable')
        <p role="alert">Der konfigurierte Mailtransport stellt keine Sicherheitscodes zu. Die Sitzung bleibt gesperrt.</p>
    @elseif ($confirmation?->delivery_status === 'failed')
        <p role="alert">Der Bestätigungscode konnte nicht zugestellt werden. Die Sitzung bleibt gesperrt.</p>
    @elseif ($confirmation?->delivery_status === 'queued')
        <p>Der Bestätigungscode wartet auf Zustellung.</p>
    @else
        <p>Geben Sie den achtstelligen Code aus der Sicherheits-E-Mail in diesem Browser ein.</p>
    @endif

    @if ($confirmation !== null)
        <p><strong>Aktuelle Code-Version: {{ $confirmation->revision }}</strong></p>
        <p>Verwenden Sie ausschließlich eine E-Mail mit dieser Code-Version. Ein neu angeforderter Code macht alle älteren E-Mails ungültig.</p>
    @endif

    <form method="post" action="{{ route('auth.confirmation.verify') }}">
        @csrf
        <label for="confirmation-code">Bestätigungscode</label>
        <input id="confirmation-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{8}" autocomplete="one-time-code" required autofocus>
        <button type="submit">Sitzung autorisieren</button>
    </form>

    <form method="post" action="{{ route('auth.confirmation.resend') }}">
        @csrf
        <button type="submit">Neuen Code senden</button>
    </form>
@endsection
