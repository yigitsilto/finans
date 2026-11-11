@extends('layouts.app')

@php
    use App\Support\Money;
    $titles = ['available' => 'Banka ve Kasa', 'cash' => 'Kasa Hesapları', 'bank' => 'Banka Hesapları', 'pos' => 'POS Hesapları'];
@endphp

@section('title', $titles[$filters['type']] ?? 'Hesaplar')

@section('content')
    <div class="stat-grid">
        <div class="stat">
            <span>Kullanılabilir nakit</span>
            <strong>{{ Money::format($summary['available']) }}</strong>
            <small>Kasa + banka</small>
        </div>
        <div class="stat">
            <span>Kasa</span>
            <strong>{{ Money::format($summary['cash']) }}</strong>
        </div>
        <div class="stat">
            <span>Banka</span>
            <strong>{{ Money::format($summary['bank']) }}</strong>
        </div>
        <div class="stat stat-muted">
            <span>Blokeli POS</span>
            <strong>{{ Money::format($summary['pos_blocked']) }}</strong>
            <small>Kullanılabilir nakde dahil değil</small>
        </div>
    </div>

    <div class="toolbar">
        <form method="GET" class="filters">
            <select name="type" aria-label="Hesap türü">
                <option value="">Tüm hesaplar</option>
                <option value="available" @selected($filters['type'] === 'available')>Banka ve kasa</option>
                <option value="cash" @selected($filters['type'] === 'cash')>Kasa</option>
                <option value="bank" @selected($filters['type'] === 'bank')>Banka</option>
                <option value="pos" @selected($filters['type'] === 'pos')>POS / Blokeli</option>
            </select>
            <select name="status" aria-label="Durum">
                <option value="active" @selected($filters['status'] === 'active')>Aktif</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>Pasif</option>
                <option value="all" @selected($filters['status'] === 'all')>Tümü</option>
            </select>
            <button class="btn btn-secondary">Filtrele</button>
        </form>
        @can('write-finance')
            <div class="actions">
                <a class="btn btn-secondary" href="{{ route('transactions.create', ['type' => 'transfer']) }}">Hesaplar arası transfer</a>
                <a class="btn" href="{{ route('accounts.create') }}">Yeni hesap</a>
            </div>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Hesap</th>
                <th>Tür</th>
                <th>Banka</th>
                <th>IBAN</th>
                <th>Durum</th>
                <th class="num">Bakiye</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($accounts as $account)
                @php($balance = $balances[$account->id] ?? '0.00')
                <tr>
                    <td><a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a></td>
                    <td>{{ $account->type->label() }}
                        @if ($account->settlementAccount)
                            <small class="muted d-block">→ {{ $account->settlementAccount->name }}</small>
                        @endif
                    </td>
                    <td>{{ $account->bank_name ?? '—' }}</td>
                    <td class="mono">{{ $account->maskedIban() ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $account->is_active ? 'badge-success' : 'badge-muted' }}">{{ $account->is_active ? 'Aktif' : 'Pasif' }}</span>
                    </td>
                    <td class="num {{ str_starts_with($balance, '-') ? 'negative' : '' }}">{{ Money::format($balance) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="muted">
                        Henüz hesap yok.
                        @can('write-finance') <a href="{{ route('accounts.create') }}">İlk hesabı oluşturun.</a> @endcan
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
