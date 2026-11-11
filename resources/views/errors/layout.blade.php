<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · Zafir Sigorta Finans</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="guest">
<main class="guest-card error-card">
    <div class="error-code">@yield('code')</div>
    <p>@yield('message')</p>
    <a href="{{ url('/') }}" class="btn btn-secondary">Ana sayfaya dön</a>
</main>
</body>
</html>
