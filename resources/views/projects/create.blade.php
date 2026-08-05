@extends('layouts.app')

@section('title', 'Projekt registrieren – AI6')

@section('content')
    <h1>Projekt registrieren</h1>

    @if ($errors->any())
        <div role="alert">
            <p>Das Projekt konnte nicht registriert werden.</p>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('projects.store') }}">
        @csrf

        <label for="name">Name</label>
        <input id="name" name="name" type="text" maxlength="255" required value="{{ old('name') }}">

        <label for="remote">SSH-Remote</label>
        <input id="remote" name="remote" type="text" maxlength="2048" required value="{{ old('remote') }}">

        <label for="control_branch">Control-Branch</label>
        <input id="control_branch" name="control_branch" type="text" maxlength="255" required value="{{ old('control_branch', 'refs/heads/main') }}">

        <label for="host_key_fingerprint">Hostkey-Fingerprint</label>
        <input id="host_key_fingerprint" name="host_key_fingerprint" type="text" maxlength="255" required value="{{ old('host_key_fingerprint') }}">

        <button type="submit">Projekt registrieren</button>
    </form>
@endsection
