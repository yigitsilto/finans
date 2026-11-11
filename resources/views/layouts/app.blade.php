<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · Zafir Sigorta Finans</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">Z</span>
            <div>
                <strong>Zafir Sigorta</strong>
                <small>Finans Yönetimi</small>
            </div>
        </div>
        <nav>
            @php
                // Parametreli öğe (ör. ?type=inflow) eşleşirse yalnızca o aktif olur; yoksa route desenine bakılır.
                $menuItems = collect(config('menu'))->pluck('items')->flatten(1)->filter(fn ($i) => $i['route']);
                $paramMatch = $menuItems->first(fn ($i) => isset($i['params']) && request()->routeIs($i['active'] ?? $i['route'])
                    && collect($i['params'])->every(fn ($v, $k) => request()->query($k) === $v));
                $isActive = fn ($i) => $paramMatch ? $i === $paramMatch
                    : (! isset($i['params']) && request()->routeIs($i['active'] ?? $i['route']));
            @endphp
            @foreach (config('menu') as $group)
                @php
                    $items = array_filter($group['items'], fn ($item) => ! isset($item['can']) || Gate::allows($item['can']));
                @endphp
                @continue(empty($items))
                @if ($group['title'])
                    <div class="nav-title">{{ $group['title'] }}</div>
                @endif
                <ul>
                    @foreach ($items as $item)
                        <li>
                            @if ($item['route'])
                                <a href="{{ route($item['route'], $item['params'] ?? []) }}"
                                   class="nav-link {{ $isActive($item) ? 'active' : '' }}">{{ $item['label'] }}</a>
                            @else
                                <span class="nav-link disabled" title="Sprint {{ $item['sprint'] }} ile gelecek">
                                    {{ $item['label'] }}<em>S{{ $item['sprint'] }}</em>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <label for="nav-toggle" class="nav-button" aria-label="Menüyü aç/kapat">☰</label>
            <h1>@yield('title', 'Dashboard')</h1>
            @auth
                <div class="user-box">
                    <span>
                        <strong>{{ auth()->user()->name }}</strong>
                        <small>{{ auth()->user()->role->label() }}</small>
                    </span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">Çıkış</button>
                    </form>
                </div>
            @endauth
        </header>

        <main class="content">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
