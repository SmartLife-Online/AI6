@extends('layouts.app')

@section('title', 'Starke Anmeldung – AI6')

@section('content')
    <h1>Starke Anmeldung</h1>
    <p>Bestätigen Sie die Anmeldung mit einem registrierten starken Verfahren.</p>

    @if ($hasTotp)
        <form method="post" action="{{ route('auth.primary.totp.verify') }}">
            @csrf
            <label for="totp-code">TOTP-Code</label>
            <input id="totp-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
            <button type="submit">TOTP bestätigen</button>
        </form>
    @endif

    @if ($hasPasskey)
        <section
            data-passkey-panel
            data-mode="get"
            data-options-url="{{ route('auth.primary.passkey.options') }}"
            data-submit-url="{{ route('auth.primary.passkey.verify') }}"
        >
            <input name="_token" type="hidden" value="{{ csrf_token() }}">
            <button type="button" data-passkey-trigger>Passkey verwenden</button>
            <p data-passkey-status role="status"></p>
        </section>
        <script src="{{ asset('assets/ai6-passkey.js') }}" defer></script>
    @endif

    @if ($hasRecoveryCodes)
        <details>
            <summary>Recovery-Code verwenden</summary>
            <form method="post" action="{{ route('auth.primary.recovery.verify') }}">
                @csrf
                <label for="recovery-code">Recovery-Code</label>
                <input id="recovery-code" name="code" type="text" autocomplete="one-time-code" required>
                <button type="submit">Recovery-Code einlösen</button>
            </form>
        </details>
    @endif
@endsection
