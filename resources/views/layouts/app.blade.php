<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $title ?? 'AI6')</title>
    <link rel="stylesheet" href="{{ asset('assets/ai6.css') }}">
</head>
<body>
    <header>
        <strong>AI6</strong>
        @auth
            <nav aria-label="Hauptnavigation">
                <a href="{{ route('projects.index') }}">Projekte</a>
                <a href="{{ route('human-requests.index') }}">Attention-Inbox</a>
                <a href="{{ route('agents.profiles') }}">Agentenprofile</a>
                <a href="{{ route('prompts.help') }}">Prompt-Hilfe</a>
                @php($navProject = request()->route('project'))
                @if ($navProject instanceof \App\AI6\Projects\Models\Project && $navProject->active_run_id !== null)
                    <a href="{{ route('projects.runs.show', [$navProject, $navProject->active_run_id]) }}">Run-Timeline</a>
                @endif
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Abmelden</button>
                </form>
            </nav>
        @endauth
    </header>

    @if ($errors->any())
        <div role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <main>
        @hasSection('content')
            @yield('content')
        @else
            {{ $slot ?? '' }}
        @endif
    </main>

    @isset($slot)
        <script src="{{ asset('assets/ai6-livewire-progress-guard.js') }}"></script>
        @livewireScripts
    @endisset
</body>
</html>
