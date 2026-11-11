@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', 'POS / Blokeli Hesaplar')

@section('content')
    <div class="alert alert-info">
        POS tahsilatları valör tarihine kadar <strong>blokeli</strong> sayılır ve kullanılabilir nakde dahil edilmez.
        Aktarım, tutarı POS hesabından bağlı banka hesabına <strong>transfer</strong> eder; aynı tutar ikinci kez giriş olarak yazılmaz.
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>POS hesabı</th><th>Banka</th><th>Aktarım hesabı</th><th class="num">Blokeli bakiye</th></tr></thead>
            <tbody>
            @forelse ($accounts as $account)
                <tr>
                    <td><a href="{{ route('accounts.show', $account) }}">{{ $account->name }}</a> @unless ($account->is_active) <span class="badge badge-muted">Pasif</span> @endunless</td>
                    <td>{{ $account->bank_name }}</td>
                    <td>{{ $account->settlementAccount?->name ?? '—' }}</td>
                    <td class="num">{{ Money::format($balances[$account->id] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Tanımlı POS hesabı yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="toolbar section-gap">
        <h2 class="section-title">Bekleyen (blokeli) POS tahsilatları</h2>
        @can('write-finance')
            <form method="POST" action="{{ route('pos.settle-due') }}" onsubmit="return confirm('Valör tarihi gelmiş tüm POS girişleri bankaya aktarılacak. Devam edilsin mi?')">
                @csrf
                <button class="btn" @disabled($dueCount === 0)>Vadesi gelenleri aktar ({{ $dueCount }})</button>
            </form>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>#</th><th>Tahsilat tarihi</th><th>Valör</th><th>POS</th><th>Açıklama</th><th class="num">Tutar</th>@can('write-finance')<th></th>@endcan</tr>
            </thead>
            <tbody>
            @forelse ($pending as $t)
                @php($due = $t->pos_settlement_date && $t->pos_settlement_date->lte(today()))
                <tr>
                    <td><a href="{{ route('transactions.show', $t) }}">{{ $t->id }}</a></td>
                    <td>{{ $t->date->format('d.m.Y') }}</td>
                    <td>{{ $t->pos_settlement_date?->format('d.m.Y') ?? '—' }}
                        @if ($due) <span class="badge badge-warning">Vadesi geldi</span> @endif
                    </td>
                    <td>{{ $t->toAccount->name }}</td>
                    <td>{{ $t->counterparty }} <span class="muted">{{ Str::limit($t->description, 50) }}</span></td>
                    <td class="num">{{ Money::format($t->amount) }}</td>
                    @can('write-finance')
                        <td class="num">
                            <form method="POST" action="{{ route('pos.settle', $t) }}" class="inline-form">
                                @csrf
                                <input type="date" name="date" class="input input-sm" value="{{ ($due ? $t->pos_settlement_date : today())->toDateString() }}"
                                       min="{{ $t->date->toDateString() }}" max="{{ today()->toDateString() }}" aria-label="Aktarım tarihi">
                                <button class="btn btn-secondary btn-sm">Bankaya aktar</button>
                            </form>
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Bekleyen POS tahsilatı yok.</td></tr>
            @endforelse
            </tbody>
            @if ($pending->isNotEmpty())
                <tfoot>
                <tr class="row-strong">
                    <td colspan="5">Toplam blokeli</td>
                    <td class="num">{{ Money::format($pending->reduce(fn ($c, $t) => $c->plus($t->amount), \Brick\Math\BigDecimal::zero())) }}</td>
                    @can('write-finance')<td></td>@endcan
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
@endsection
