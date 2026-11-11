@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', 'Müşteriler')

@section('content')
    <div class="toolbar">
        <form method="GET" class="filters">
            <input type="search" name="q" class="input" placeholder="Müşteri adı" value="{{ $filters['q'] ?? '' }}">
            <select name="status" aria-label="Durum">
                <option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Aktif</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Pasif</option>
                <option value="all" @selected(($filters['status'] ?? '') === 'all')>Tümü</option>
            </select>
            <button class="btn btn-secondary">Filtrele</button>
        </form>
        @can('write-finance')
            <a class="btn" href="{{ route('customers.create') }}">Yeni müşteri</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Müşteri</th><th>Tür</th><th>Telefon</th><th class="num">Açık alacak</th></tr></thead>
            <tbody>
            @forelse ($customers as $c)
                <tr>
                    <td><a href="{{ route('customers.show', $c) }}">{{ $c->name }}</a> @unless ($c->is_active) <span class="badge badge-muted">Pasif</span> @endunless</td>
                    <td>{{ $c->typeLabel() }}</td>
                    <td>{{ $c->phone ?? '—' }}</td>
                    <td class="num">{{ Money::format($c->outstanding) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Kayıtlı müşteri yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $customers->links() }}
@endsection
