@extends('layouts.app')

@php
    use App\Models\Receivable;
    use App\Support\Money;
    $statusBadges = ['open' => 'badge-muted', 'partial' => 'badge-warning', 'paid' => 'badge-success', 'cancelled' => 'badge-muted'];
    $sortLink = function (string $column, string $label) use ($filters) {
        $dir = (($filters['sort'] ?? 'due_date') === $column && ($filters['dir'] ?? 'asc') === 'asc') ? 'desc' : 'asc';
        return '<a href="'.e(request()->fullUrlWithQuery(['sort' => $column, 'dir' => $dir, 'page' => null])).'">'.e($label).'</a>';
    };
@endphp

@section('title', 'Müşteri Alacakları')

@section('content')
    <div class="stat-grid">
        <div class="stat"><span>Toplam alacak</span><strong>{{ Money::format($totals->amount) }}</strong><small>{{ $totals->count }} kayıt · filtreye göre, iptaller hariç</small></div>
        <div class="stat"><span>Tahsil edilen</span><strong>{{ Money::format($totals->collected) }}</strong></div>
        <div class="stat"><span>Kalan</span><strong>{{ Money::format($totals->remaining) }}</strong></div>
    </div>

    <div class="toolbar">
        <form method="GET" class="filters">
            <input type="search" name="q" class="input" placeholder="Poliçe no, müşteri, sigorta şirketi" value="{{ $filters['q'] ?? '' }}">
            <select name="customer_id" aria-label="Müşteri">
                <option value="">Tüm müşteriler</option>
                @foreach ($customers as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="Durum">
                @foreach (['outstanding' => 'Kalanı olanlar', 'overdue' => 'Vadesi geçenler', 'open' => 'Açık (hiç tahsilat yok)', 'partial' => 'Kısmi tahsil', 'paid' => 'Tahsil edildi', 'cancelled' => 'İptal', 'all' => 'Tümü'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" name="due_from" class="input" value="{{ $filters['due_from'] ?? '' }}" aria-label="Vade başlangıç">
            <input type="date" name="due_to" class="input" value="{{ $filters['due_to'] ?? '' }}" aria-label="Vade bitiş">
            <button class="btn btn-secondary">Filtrele</button>
        </form>
        @can('write-finance')
            <a class="btn" href="{{ route('receivables.create') }}">Yeni alacak</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Poliçe / referans</th><th>Müşteri</th><th>{!! $sortLink('issue_date', 'Tarih') !!}</th><th>{!! $sortLink('due_date', 'Vade') !!}</th>
                <th class="num">{!! $sortLink('amount', 'Alacak') !!}</th><th class="num">Tahsil edilen</th><th class="num">Kalan</th><th>Durum</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($receivables as $r)
                @php($status = $r->status())
                <tr class="{{ $status === 'cancelled' ? 'row-reversed' : '' }}">
                    <td><a href="{{ route('receivables.show', $r) }}">{{ $r->reference_no }}</a>
                        @if ($r->insurance_company) <small class="muted d-block">{{ $r->insurance_company }}</small> @endif
                    </td>
                    <td><a href="{{ route('customers.show', $r->customer) }}">{{ $r->customer->name }}</a></td>
                    <td>{{ $r->issue_date->format('d.m.Y') }}</td>
                    <td>{{ $r->due_date->format('d.m.Y') }}
                        @if ($r->isOverdue()) <span class="badge badge-danger">{{ (int) $r->due_date->diffInDays(today()) }} gün gecikti</span> @endif
                    </td>
                    <td class="num">{{ Money::format($r->amount) }}</td>
                    <td class="num">{{ Money::format($r->collectedAmount()) }}</td>
                    <td class="num"><strong>{{ Money::format($status === 'cancelled' ? 0 : $r->remainingAmount()) }}</strong></td>
                    <td><span class="badge {{ $statusBadges[$status] }}">{{ Receivable::STATUS_LABELS[$status] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Kayıt bulunamadı.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $receivables->links() }}
@endsection
