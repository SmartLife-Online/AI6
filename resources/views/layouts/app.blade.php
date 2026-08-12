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
        @livewireScripts
    @endisset
</body>
</html>
