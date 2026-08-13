@extends('layouts.app')

@section('title', 'Agentenprofile – AI6')

@section('content')
    <h1>Agentenprofile</h1>
    <p>Promptkatalog: Version {{ $catalogVersion }}</p>

    @foreach ($profiles as $profile)
        <section aria-labelledby="profile-{{ $loop->index }}">
            <h2 id="profile-{{ $loop->index }}">{{ $profile->id }}</h2>
            <dl>
                <dt>Providerprofil</dt>
                <dd>{{ $profile->providerProfileAlias }}</dd>
                <dt>Adapter</dt>
                <dd>{{ $profile->adapterId }}</dd>
                <dt>Capability-Status</dt>
                <dd>{{ $profile->capabilityStatus->value }}</dd>
                <dt>Runtime-Profil</dt>
                <dd>{{ $profile->runtimeProfileId }} (Version {{ $runtimeProfiles->get($profile->runtimeProfileId)->version }})</dd>
                <dt>Rollen</dt>
                <dd>{{ implode(', ', array_map(static fn ($role) => $role->value, $profile->roles)) }}</dd>
                <dt>Modelle</dt>
                <dd>{{ implode(', ', $profile->models) }}</dd>
                <dt>Efforts</dt>
                <dd>{{ implode(', ', $profile->efforts) }}</dd>
            </dl>
        </section>
    @endforeach
@endsection
