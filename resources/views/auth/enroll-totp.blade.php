@extends('layouts.app')

@section('title', 'TOTP einrichten – AI6')

@section('content')
    <h1>TOTP einrichten</h1>
    <p>Hinterlegen Sie dieses Geheimnis in Ihrer Authenticator-App:</p>
    <p><code>{{ $secret }}</code></p>

    <form method="post" action="{{ route('auth.enrollment.totp.confirm') }}">
        @csrf
        <label for="totp-code">Aktueller sechsstelliger Code</label>
        <input id="totp-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" required>
        <button type="submit">TOTP registrieren</button>
    </form>

    <p><a href="{{ route('auth.enrollment.passkey.show') }}">Stattdessen einen Passkey registrieren</a></p>
@endsection
