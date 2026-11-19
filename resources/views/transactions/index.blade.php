@extends('layouts.app')

@php
    use App\Support\Money;
    $titles = ['inflow' => 'Nakit Girişleri', 'outflow' => 'Nakit Çıkışları', 'transfer' => 'Transferler', 'opening' => 'Açılış Bakiyeleri'];
    $sortLink = function (string $column, string $label) use ($filters) {
        $dir = (($filters['sort'] ?? 'date') === $column && ($filters['dir'] ?? 'desc') === 'desc') ? 'asc' : 'desc';
        return '<a href="'.e(request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null])).'">'.e($label).'</a>';
    };
@endphp

@section('title', $type ? $titles[$type->value] : 'Tüm Hareketler')

@section('content')
    <div class="toolbar">
        <form method="GET" class="filters">
            @if ($type)
                <input type="hidden" name="type" value="{{ $type->value }}">
            @else
                <select name="type" aria-label="İşlem türü">
                    <option value="">Tüm türler</option>
                    @foreach (\App\Enums\TransactionType::cases() as $case)
                        <option value="{{ $case->value }}">{{ $case->label() }}</option>
                    @endforeach
                </select>
            @endif
            <input type="date" name="from" class="input" value="{{ $filters['from'] ?? '' }}" aria-label="Başlangıç tarihi">
            <input type="date" name="to" class="input" value="{{ $filters['to'] ?? '' }}" aria-label="Bitiş tarihi">
            <select name="account_id" aria-label="Hesap">
                <option value="">Tüm hesaplar</option>
                @foreach ($accounts as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['account_id'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
            @if ($categories->isNotEmpty())
                <select name="category_id" aria-label="Kategori">
                    <option value="">Tüm kategoriler</option>
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
            <input type="search" name="q" class="input" placeholder="Açıklama, kişi, referans" value="{{ $filters['q'] ?? '' }}">
            <select name="status" aria-label="Kayıt durumu">
                <option value="effective" @selected(($filters['status'] ?? 'effective') === 'effective')>Geçerli kayıtlar</option>
                <option value="all" @selected(($filters['status'] ?? '') === 'all')>İptaller dahil</option>
            </select>
            <button class="btn btn-secondary">Filtrele</button>
        </form>
        <div class="actions">
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'transactions', 'format' => 'xlsx'] + request()->only(['type', 'from', 'to', 'account_id', 'category_id', 'q', 'status'])) }}">Excel</a>
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'transactions', 'format' => 'pdf'] + request()->only(['type', 'from', 'to', 'account_id', 'category_id', 'q', 'status'])) }}">PDF</a>
        @can('write-finance')
            <a class="btn" href="{{ route('transactions.create', ['type' => $type?->value ?? 'inflow']) }}">
                {{ match ($type?->value) { 'outflow' => 'Yeni çıkış', 'transfer' => 'Yeni transfer', 'opening' => 'Yeni açılış', default => 'Yeni giriş' } }}
            </a>
        @endcan
        </div>
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>{!! $sortLink('date', 'Tarih') !!}</th>
                <th>{!! $sortLink('id', '#') !!}</th>
                @unless ($type) <th>Tür</th> @endunless
                <th>Kategori</th>
                <th>Hesap</th>
                <th>İlgili / Açıklama</th>
                <th class="num">{!! $sortLink('amount', 'Tutar') !!}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($transactions as $t)
                <tr class="{{ $t->isReversal() || $t->reversal ? 'row-reversed' : '' }}">
                    <td>{{ $t->date->format('d.m.Y') }}</td>
                    <td><a href="{{ route('transactions.show', $t) }}">{{ $t->id }}</a></td>
                    @unless ($type) <td>{{ $t->type->label() }}</td> @endunless
                    <td>{{ $t->category?->fullName() ?? '—' }}</td>
                    <td>
                        @if ($t->fromAccount) {{ $t->fromAccount->name }} @endif
                        @if ($t->fromAccount && $t->toAccount) → @endif
                        @if ($t->toAccount) {{ $t->toAccount->name }} @endif
                    </td>
                    <td>
                        @if ($t->partner) <strong>{{ $t->partner->name }}</strong> @elseif ($t->counterparty) <strong>{{ $t->counterparty }}</strong> @endif
                        <span class="muted">{{ Str::limit($t->description, 60) }}</span>
                        @if ($t->isReversal()) <span class="badge badge-danger">Ters kayıt</span>
                        @elseif ($t->reversal) <span class="badge badge-muted">İptal edildi</span> @endif
                    </td>
                    <td class="num {{ str_starts_with((string) $t->amount, '-') ? 'negative' : '' }}">{{ Money::format($t->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Kayıt bulunamadı.</td></tr>
            @endforelse
            </tbody>
            @if ($type && $transactions->total() > 0)
                <tfoot>
                <tr class="row-strong">
                    <td colspan="{{ 5 }}">Toplam ({{ $transactions->total() }} kayıt, filtreye göre)</td>
                    <td class="num">{{ Money::format($total) }}</td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>

    {{ $transactions->links() }}
@endsection
