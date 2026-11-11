@extends('layouts.app')

@section('title', 'Kullanıcılar')

@section('content')
    <div class="toolbar">
        <form method="GET" action="{{ route('users.index') }}" class="filters">
            <input type="search" name="q" class="input" placeholder="Ad veya kullanıcı adı" value="{{ $filters['q'] ?? '' }}">
            <select name="role" aria-label="Rol">
                <option value="">Tüm roller</option>
                @foreach ($roles as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="Durum">
                <option value="">Tüm durumlar</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Aktif</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Pasif</option>
            </select>
            <button type="submit" class="btn btn-secondary">Filtrele</button>
        </form>
        <a href="{{ route('users.create') }}" class="btn">Yeni kullanıcı</a>
    </div>

    @php
        $sortLink = function (string $column, string $label) use ($filters) {
            $dir = (($filters['sort'] ?? 'name') === $column && ($filters['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
            $url = request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null]);
            return '<a href="'.e($url).'">'.e($label).'</a>';
        };
    @endphp

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>{!! $sortLink('name', 'Ad Soyad') !!}</th>
                <th>{!! $sortLink('username', 'Kullanıcı adı') !!}</th>
                <th>{!! $sortLink('role', 'Rol') !!}</th>
                <th>Durum</th>
                <th>{!! $sortLink('last_login_at', 'Son giriş') !!}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->name }}</td>
                    <td>{{ $user->username }}</td>
                    <td>{{ $user->role->label() }}</td>
                    <td>
                        <span class="badge {{ $user->is_active ? 'badge-success' : 'badge-muted' }}">
                            {{ $user->is_active ? 'Aktif' : 'Pasif' }}
                        </span>
                    </td>
                    <td>{{ $user->last_login_at?->format('d.m.Y H:i') ?? '—' }}</td>
                    <td class="num"><a href="{{ route('users.edit', $user) }}">Düzenle</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Kayıt bulunamadı.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
@endsection
