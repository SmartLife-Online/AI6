@extends('layouts.app')

@section('title', 'Passkey einrichten – AI6')

@section('content')
    <h1>Passkey einrichten</h1>
    <p>Registrieren Sie einen Passkey für diese Identität. Die Enrollment-Sitzung erteilt keine Anwendungsberechtigung.</p>

    <section
        data-passkey-panel
        data-mode="create"
        data-options-url="{{ route('auth.enrollment.passkey.options') }}"
        data-submit-url="{{ route('auth.enrollment.passkey.register') }}"
    >
        <input name="_token" type="hidden" value="{{ csrf_token() }}">
        <label for="passkey-label">Bezeichnung</label>
        <input id="passkey-label" name="label" type="text" maxlength="255" autocomplete="off">
        <button type="button" data-passkey-trigger>Passkey registrieren</button>
        <p data-passkey-status role="status"></p>
    </section>

    <p><a href="{{ route('auth.enrollment.totp.show') }}">Stattdessen TOTP einrichten</a></p>
    <script src="{{ asset('assets/ai6-passkey.js') }}" defer></script>
@endsection
