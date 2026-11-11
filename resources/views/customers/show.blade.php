@extends('layouts.app')

@php
    use App\Models\Receivable;
    use App\Support\Money;
    use Brick\Math\BigDecimal;
    $active = $receivables->whereNull('cancelled_at');
    $total = $active->reduce(fn ($c, $r) => $c->plus($r->amount), BigDecimal::zero());
    $collected = $active->reduce(fn ($c, $r) => $c->plus($r->collectedAmount()), BigDecimal::zero());
    $overdue = $active->filter->isOverdue()->reduce(fn ($c, $r) => $c->plus($r->remainingAmount()), BigDecimal::zero());
@endphp

@section('title', $customer->name)

@section('content')
    <div class="stat-grid">
        <div class="stat"><span>Toplam alacak</span><strong>{{ Money::format($total) }}</strong></div>
        <div class="stat"><span>Tahsil edilen</span><strong>{{ Money::format($collected) }}</strong></div>
        <div class="stat"><span>Kalan</span><strong>{{ Money::format($total->minus($collected)) }}</strong></div>
        <div class="stat"><span>Vadesi geçmiş</span><strong class="{{ $overdue->isPositive() ? 'negative' : '' }}">{{ Money::format($overdue) }}</strong></div>
    </div>

    <div class="toolbar">
        <div class="meta">
            <span class="badge badge-muted">{{ $customer->typeLabel() }}</span>
            @if ($customer->maskedTaxNumber()) <span class="mono">{{ $customer->type === 'corporate' ? 'VKN' : 'TCKN' }}: {{ $customer->maskedTaxNumber() }}</span> @endif
            @if ($customer->phone) <span>{{ $customer->phone }}</span> @endif
            @if ($customer->email) <span>{{ $customer->email }}</span> @endif
            @unless ($customer->is_active) <span class="badge badge-muted">Pasif</span> @endunless
        </div>
        @can('write-finance')
            <div class="actions">
                <a class="btn btn-secondary" href="{{ route('customers.edit', $customer) }}">Düzenle</a>
                @if ($customer->is_active)
                    <a class="btn" href="{{ route('receivables.create', ['customer_id' => $customer->id]) }}">Yeni alacak</a>
                @endif
            </div>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Poliçe / referans</th><th>Tarih</th><th>Vade</th><th class="num">Alacak</th><th class="num">Tahsil edilen</th><th class="num">Kalan</th><th>Durum</th></tr></thead>
            <tbody>
            @forelse ($receivables as $r)
                @php($status = $r->status())
                <tr class="{{ $status === 'cancelled' ? 'row-reversed' : '' }}">
                    <td><a href="{{ route('receivables.show', $r) }}">{{ $r->reference_no }}</a></td>
                    <td>{{ $r->issue_date->format('d.m.Y') }}</td>
                    <td>{{ $r->due_date->format('d.m.Y') }} @if ($r->isOverdue()) <span class="badge badge-danger">Gecikti</span> @endif</td>
                    <td class="num">{{ Money::format($r->amount) }}</td>
                    <td class="num">{{ Money::format($r->collectedAmount()) }}</td>
                    <td class="num">{{ Money::format($status === 'cancelled' ? 0 : $r->remainingAmount()) }}</td>
                    <td>{{ Receivable::STATUS_LABELS[$status] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Bu müşteriye ait alacak yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($customer->notes)
        <p class="muted section-gap">{{ $customer->notes }}</p>
    @endif
@endsection
