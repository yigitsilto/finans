@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', $loan->name)

@section('content')
    <div class="stat-grid">
        <div class="stat"><span>Kredi tutarı</span><strong>{{ Money::format($summary['principal']) }}</strong>
            <small>{{ $loan->bank_name }} · %{{ rtrim(rtrim(str_replace('.', ',', $loan->annual_interest_rate), '0'), ',') }} · {{ $loan->term_months }} ay</small></div>
        <div class="stat"><span>Kalan anapara</span><strong>{{ Money::format($summary['remaining_principal']) }}</strong>
            <small>{{ $summary['paid_count'] }} / {{ $summary['total_count'] }} taksit ödendi</small></div>
        <div class="stat"><span>Ödenen anapara / faiz</span><strong>{{ Money::format($summary['paid_principal']) }}</strong>
            <small>Faiz: {{ Money::format($summary['paid_interest']) }}</small></div>
        <div class="stat"><span>Kalan faiz (plan)</span><strong>{{ Money::format($summary['remaining_interest']) }}</strong></div>
    </div>

    @if ($summary['plan_difference'] !== '0.00')
        <div class="alert alert-warning">
            Taksit planındaki anapara toplamı ({{ Money::format($summary['planned_principal']) }}) kredi tutarıyla eşleşmiyor.
            Fark: {{ Money::format($summary['plan_difference']) }}. Taksitleri bankanın planına göre kontrol edin.
        </div>
    @endif

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>#</th><th>Vade</th><th class="num">Anapara</th><th class="num">Faiz</th><th class="num">Taksit</th><th>Durum</th>@can('write-finance')<th></th>@endcan</tr>
            </thead>
            <tbody>
            @foreach ($installments as $inst)
                @php
                    $paid = $inst->payments->isNotEmpty();
                    $isNext = $summary['next']?->id === $inst->id;
                    $isLastPaid = $paid && ! $installments->first(fn ($i) => $i->number > $inst->number && $i->payments->isNotEmpty());
                @endphp
                <tr class="{{ $isNext ? 'row-highlight' : '' }}">
                    <td>{{ $inst->number }}</td>
                    <td>{{ $inst->due_date->format('d.m.Y') }}</td>
                    <td class="num">{{ Money::format($inst->principal) }}</td>
                    <td class="num">{{ Money::format($inst->interest) }}</td>
                    <td class="num">{{ Money::format($inst->total) }}</td>
                    <td>
                        @if ($paid)
                            <span class="badge badge-success">Ödendi</span>
                            <small class="muted">{{ $inst->payments->first()->date->format('d.m.Y') }}
                                @foreach ($inst->payments as $p) · <a href="{{ route('transactions.show', $p) }}">#{{ $p->id }}</a> @endforeach
                            </small>
                        @elseif ($inst->due_date->lt(today()))
                            <span class="badge badge-danger">Gecikti</span>
                        @else
                            <span class="badge badge-muted">Bekliyor</span>
                        @endif
                    </td>
                    @can('write-finance')
                        <td class="num">
                            @if ($isNext)
                                <details class="inline-details">
                                    <summary class="btn btn-sm">Öde</summary>
                                    <form method="POST" action="{{ route('loans.installments.pay', $inst) }}" class="stack-form">
                                        @csrf
                                        <label>Ödeme tarihi <input type="date" name="date" class="input input-sm" value="{{ min(today(), $inst->due_date)->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
                                        <label>Hesap
                                            <select name="account_id" class="input-sm" required>
                                                @foreach ($banks as $id => $label)
                                                    <option value="{{ $id }}" @selected($id === $loan->account_id)>{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <button class="btn btn-sm">{{ Money::format($inst->total) }} öde</button>
                                    </form>
                                </details>
                                @include('loans._edit-installment')
                            @elseif (! $paid)
                                @include('loans._edit-installment')
                            @elseif ($isLastPaid)
                                <details class="inline-details">
                                    <summary class="btn btn-secondary btn-sm">Ödemeyi iptal et</summary>
                                    <form method="POST" action="{{ route('loans.installments.reverse', $inst) }}" class="stack-form" onsubmit="return confirm('Taksit ödemesi (anapara + faiz) iptal edilecek.')">
                                        @csrf
                                        <label>İptal tarihi <input type="date" name="date" class="input input-sm" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
                                        <label>Neden <input name="reason" class="input input-sm" required minlength="3"></label>
                                        <button class="btn btn-danger btn-sm">İptal et</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="row-strong">
                <td colspan="2">Toplam</td>
                <td class="num">{{ Money::format($summary['planned_principal']) }}</td>
                <td class="num">{{ Money::format($installments->reduce(fn ($c, $i) => $c->plus($i->interest), \Brick\Math\BigDecimal::zero())) }}</td>
                <td class="num">{{ Money::format($installments->reduce(fn ($c, $i) => $c->plus($i->total), \Brick\Math\BigDecimal::zero())) }}</td>
                <td colspan="{{ auth()->user()->can('write-finance') ? 2 : 1 }}"></td>
            </tr>
            </tfoot>
        </table>
    </div>

    @if ($loan->notes)
        <p class="muted section-gap">{{ $loan->notes }}</p>
    @endif
@endsection
