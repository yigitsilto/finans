<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if (config('app.demo'))
        <meta name="robots" content="noindex, nofollow">
    @endif
    <title>@yield('title') · Zafir Sigorta Finans</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="guest">
@if (config('app.demo'))
    <div class="demo-banner">Demo ortamı – gerçek finansal veri girmeyin. Kayıtlar örnek amaçlıdır.</div>
@endif
<main class="guest-card">
    <div class="brand brand-dark">
        <span class="brand-mark">Z</span>
        <div>
            <strong>Zafir Sigorta</strong>
            <small>Finans Yönetimi</small>
        </div>
    </div>
    @yield('content')
</main>
</body>
</html>
