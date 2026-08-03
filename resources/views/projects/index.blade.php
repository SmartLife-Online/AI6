@extends('layouts.app')

@section('title', 'Projekte – AI6')

@section('content')
    <h1>Projekte</h1>

    @if ($projects->isEmpty())
        <p>Keine Projekte verfügbar.</p>
    @else
        <ul>
            @foreach ($projects as $project)
                <li><a href="{{ route('projects.show', $project) }}">{{ $project->name }}</a></li>
            @endforeach
        </ul>
    @endif
@endsection
