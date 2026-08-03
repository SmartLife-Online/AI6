@extends('layouts.app')

@section('title', $project->name.' – AI6')

@section('content')
    <h1>{{ $project->name }}</h1>
    <p>Projekt-ID: {{ $project->getKey() }}</p>
@endsection
