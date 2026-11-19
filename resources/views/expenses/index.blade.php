@extends('layouts.app')

@php
    use App\Support\Money;
    $statusLabels = ['paid' => ['Ödendi', 'badge-success'], 'pending' => ['Bekliyor', 'badge-muted'], 'overdue' => ['Gecikti', 'badge-danger']];
    $monthName = $period->locale('tr')->translatedFormat('F Y');
    $pct = fn (?string $v) => $v === null ? '—' : '%'.str_replace('.', ',', $v);
@endphp

@section('title', 'Giderler')

@section('content')
    <div class="toolbar">
        <form method="GET" class="filters">
            <a class="btn btn-secondary" href="{{ route('expenses.index', ['month' => $period->subMonth()->format('Y-m')]) }}" aria-label="Önceki ay">‹</a>
            <input type="month" name="month" class="input" value="{{ $period->format('Y-m') }}" onchange="this.form.submit()">
            <a class="btn btn-secondary" href="{{ route('expenses.index', ['month' => $period->addMonth()->format('Y-m')]) }}" aria-label="Sonraki ay">›</a>
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'expenses', 'format' => 'xlsx', 'from' => $period->startOfMonth()->toDateString(), 'to' => $period->endOfMonth()->toDateString()]) }}">Excel</a>
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'expenses', 'format' => 'pdf', 'from' => $period->startOfMonth()->toDateString(), 'to' => $period->endOfMonth()->toDateString()]) }}">PDF</a>
        </form>
        @can('write-finance')
            <div class="actions">
                <a class="btn btn-secondary" href="{{ route('transactions.create', ['type' => 'outflow']) }}">Tek seferlik gider</a>
                <a class="btn" href="{{ route('expenses.create') }}">Yeni tekrarlayan gider</a>
            </div>
        @endcan
    </div>

    <div class="stat-grid">
        <div class="stat"><span>{{ $monthName }} toplam nakit çıkışı</span><strong>{{ Money::format($analysis['total_outflow']) }}</strong></div>
        <div class="stat"><span>Sabit giderler</span><strong>{{ Money::format($analysis['fixed']) }}</strong><small>Oran: {{ $pct($analysis['fixed_ratio']) }}</small></div>
        <div class="stat"><span>Değişken giderler</span><strong>{{ Money::format($analysis['variable']) }}</strong><small>Oran: {{ $pct($analysis['variable_ratio']) }}</small></div>
        <div class="stat stat-muted"><span>Diğer çıkışlar</span><strong>{{ Money::format($analysis['other']) }}</strong><small>Prim, kredi, ortak vb.</small></div>
    </div>

    <h2 class="section-title">Tekrarlayan giderler · {{ $monthName }}</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Gider</th><th>Kategori</th><th>Vade</th><th class="num">Beklenen</th><th>Durum</th><th class="num">Ödenen</th>@can('write-finance')<th></th>@endcan</tr></thead>
            <tbody>
            @forelse ($rows as $row)
                @php([$label, $badge] = $statusLabels[$row['status']])
                @php($e = $row['expense'])
                <tr>
                    <td>{{ $e->name }} @unless ($e->is_active) <span class="badge badge-muted">Pasif</span> @endunless
                        @if ($e->counterparty) <small class="muted d-block">{{ $e->counterparty }}</small> @endif
                    </td>
                    <td>{{ $e->category->fullName() }}</td>
                    <td>{{ $row['due_date']->format('d.m.Y') }}</td>
                    <td class="num">{{ Money::format($e->amount) }}</td>
                    <td><span class="badge {{ $badge }}">{{ $label }}</span></td>
                    <td class="num">
                        @if ($row['payment'])
                            <a href="{{ route('transactions.show', $row['payment']) }}">{{ Money::format($row['payment']->amount) }}</a>
                            <small class="muted d-block">{{ $row['payment']->date->format('d.m.Y') }}</small>
                        @else — @endif
                    </td>
                    @can('write-finance')
                        <td class="num">
                            @unless ($row['payment'])
                                <details class="inline-details">
                                    <summary class="btn btn-sm">Öde</summary>
                                    <form method="POST" action="{{ route('expenses.pay', $e) }}" class="stack-form">
                                        @csrf
                                        <input type="hidden" name="month" value="{{ $period->format('Y-m') }}">
                                        <div class="modal-title">{{ $e->name }} · {{ $monthName }}</div>
                                        <label>Tarih <input type="date" name="date" class="input input-sm" value="{{ min(today(), $row['due_date'])->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
                                        <label>Tutar <input name="amount" class="input input-sm num-input" value="{{ Money::format($e->amount, false) }}" required></label>
                                        <label>Hesap
                                            <select name="account_id" class="input-sm" required>
                                                @foreach ($accounts as $id => $name)
                                                    <option value="{{ $id }}" @selected($id === $e->account_id)>{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <button class="btn btn-sm">Ödemeyi kaydet</button>
                                    </form>
                                </details>
                            @endunless
                            <a class="btn btn-secondary btn-sm" href="{{ route('expenses.edit', $e) }}">Düzenle</a>
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Bu ay için tekrarlayan gider tanımı yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="section-title">Kategoriye göre nakit çıkışları · {{ $monthName }}</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Grup</th><th>Kategori</th><th>Tür</th><th class="num">Tutar</th><th class="num">Pay</th></tr></thead>
            <tbody>
            @forelse ($analysis['by_category'] as $r)
                <tr>
                    <td>{{ $r->group_name }}</td>
                    <td>{{ $r->category_name }}</td>
                    <td>{{ ['fixed' => 'Sabit', 'variable' => 'Değişken'][$r->cost_type] ?? ($r->activity === 'financing' ? 'Finansman' : 'Diğer') }}</td>
                    <td class="num">{{ Money::format($r->total) }}</td>
                    <td class="num">{{ \Brick\Math\BigDecimal::of($analysis['total_outflow'])->isZero() ? '—' : '%'.str_replace('.', ',', (string) \Brick\Math\BigDecimal::of($r->total)->multipliedBy(100)->dividedBy($analysis['total_outflow'], 1, \Brick\Math\RoundingMode::HALF_UP)) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Bu ay nakit çıkışı yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
