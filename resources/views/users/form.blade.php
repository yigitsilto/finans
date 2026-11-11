@extends('layouts.app')

@php($editing = $user->exists)

@section('title', $editing ? 'Kullanıcı düzenle' : 'Yeni kullanıcı')

@section('content')
    <div class="card form-card">
        <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div class="form-grid">
                <div class="form-row">
                    <label for="name">Ad soyad</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $user->name) }}" required maxlength="100">
                </div>
                <div class="form-row">
                    <label for="username">Kullanıcı adı</label>
                    <input id="username" name="username" class="input" value="{{ old('username', $user->username) }}"
                           required maxlength="50" autocapitalize="none">
                    <small class="muted">Küçük harf, rakam, nokta, tire ve alt çizgi.</small>
                </div>
                <div class="form-row">
                    <label for="email">E-posta <span class="muted">(opsiyonel)</span></label>
                    <input id="email" name="email" type="email" class="input" value="{{ old('email', $user->email) }}" maxlength="255">
                </div>
                <div class="form-row">
                    <label for="role">Rol</label>
                    <select id="role" name="role" required>
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role', $user->role?->value ?? 'viewer') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($editing)
                    <div class="form-row">
                        <label for="is_active">Durum</label>
                        <select id="is_active" name="is_active">
                            <option value="1" @selected((string) old('is_active', (int) $user->is_active) === '1')>Aktif</option>
                            <option value="0" @selected((string) old('is_active', (int) $user->is_active) === '0')>Pasif</option>
                        </select>
                    </div>
                @endif
            </div>

            <fieldset class="fieldset">
                <legend>{{ $editing ? 'Parola değiştir (boş bırakılırsa değişmez)' : 'Parola' }}</legend>
                <div class="form-grid">
                    <div class="form-row">
                        <label for="password">Parola</label>
                        <input id="password" name="password" type="password" class="input" autocomplete="new-password" @required(! $editing)>
                        <small class="muted">En az 10 karakter, harf ve rakam içermeli.</small>
                    </div>
                    <div class="form-row">
                        <label for="password_confirmation">Parola (tekrar)</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" @required(! $editing)>
                    </div>
                </div>
            </fieldset>

            <div class="form-actions">
                <a href="{{ route('users.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button type="submit" class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
