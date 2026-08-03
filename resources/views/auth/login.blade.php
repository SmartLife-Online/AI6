@extends('layouts.app')

@section('title', 'Anmelden – AI6')

@section('content')
    <h1>Anmelden</h1>

    <form method="post" action="{{ route('login.store') }}">
        @csrf

        <label for="email">E-Mail-Adresse</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button type="submit">Anmelden</button>
    </form>
@endsection
