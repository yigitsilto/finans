@extends('layouts.guest')

@section('title', 'Giriş')

@section('content')
    <h1 class="guest-title">Giriş yap</h1>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf
        <div class="form-row">
            <label for="username">Kullanıcı adı</label>
            <input id="username" name="username" type="text" class="input" value="{{ old('username') }}"
                   autocomplete="username" autocapitalize="none" required autofocus>
        </div>
        <div class="form-row">
            <label for="password">Parola</label>
            <input id="password" name="password" type="password" class="input" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-block">Giriş yap</button>
    </form>
@endsection
