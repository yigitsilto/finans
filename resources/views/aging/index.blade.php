@extends('layouts.app')

@php
    use App\Services\AgingService;
    use App\Support\Money;
    $buckets = AgingService::BUCKETS;
    $pct = fn (?string $v) => $v === null ? '—' : '%'.str_replace('.', ',', $v);
    $isToday = $report['date']->isSameDay(today());
@endphp

@section('title', 'Alacak Yaşlandırma')

@section('content')
    <form method="GET" class="filters card-filters">
        <label>Rapor tarihi <input type="date" name="date" class="input" value="{{ $report['date']->toDateString() }}" max="{{ today()->toDateString() }}"></label>
        <label>Müşteri
            <select name="customer_id">
                <option value="">Tüm müşteriler</option>
                @foreach ($customers as $id => $name)
                    <option value="{{ $id }}" @selected((string) ($filters['customer_id'] ?? '') === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        <label>Görünüm
            <select name="view">
                <option value="customer" @selected($filters['view'] === 'customer')>Müşteri bazlı</option>
                <option value="detail" @selected($filters['view'] === 'detail')>Alacak detayı</option>
            </select>
        </label>
        <button class="btn btn-secondary">Göster</button>
    </form>

    @unless ($isToday)
        <div class="alert alert-info">
            {{ $report['date']->format('d.m.Y') }} tarihi itibarıyla rapor: bu tarihten sonraki tahsilatlar ve alacaklar dahil değildir.
        </div>
    @endunless

    <div class="aging-grid">
        @foreach ($buckets as $key => $meta)
            @php($b = $report['buckets'][$key])
            <a class="aging-card level-{{ $meta['level'] }} {{ ($filters['bucket'] ?? null) === $key ? 'selected' : '' }}"
               href="{{ request()->fullUrlWithQuery(['view' => 'detail', 'bucket' => ($filters['bucket'] ?? null) === $key ? null : $key]) }}">
                <span>{{ $meta['label'] }}</span>
                <strong>{{ Money::format($b['amount']) }}</strong>
                <small>{{ $meta['risk'] }} · {{ $b['count'] }} alacak · {{ $pct($b['percent']) }}</small>
            </a>
        @endforeach
    </div>

    <div class="stat-grid">
        <div class="stat"><span>Toplam kalan alacak</span><strong>{{ Money::format($report['total']) }}</strong><small>{{ $report['count'] }} alacak</small></div>
        <div class="stat"><span>Vadesi geçmiş</span><strong class="{{ $report['overdue'] !== '0.00' ? 'negative' : '' }}">{{ Money::format($report['overdue']) }}</strong></div>
        <div class="stat"><span>90+ gün kritik</span><strong class="{{ $report['buckets']['d90_plus']['amount'] !== '0.00' ? 'negative' : '' }}">{{ Money::format($report['buckets']['d90_plus']['amount']) }}</strong></div>
    </div>

    @if ($report['buckets']['d90_plus']['count'] > 0)
        <div class="alert alert-danger">Kritik tahsilat riski bulunmaktadır: {{ $report['buckets']['d90_plus']['count'] }} alacak 90 günü aştı.</div>
    @endif

    @if ($filters['view'] === 'customer')
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Müşteri</th>
                    @foreach ($buckets as $meta) <th class="num">{{ $meta['label'] }}</th> @endforeach
                    <th class="num">Toplam</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($report['customers'] as $c)
                    <tr>
                        <td><a href="{{ route('customers.show', $c['id']) }}">{{ $c['name'] }}</a></td>
                        @foreach ($buckets as $key => $meta)
                            <td class="num {{ $c['buckets'][$key] !== '0.00' && in_array($meta['level'], ['high', 'critical'], true) ? 'negative' : '' }}">
                                {{ $c['buckets'][$key] === '0.00' ? '—' : Money::format($c['buckets'][$key]) }}
                            </td>
                        @endforeach
                        <td class="num"><strong>{{ Money::format($c['total']) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">Bu tarihte kalan alacak yok.</td></tr>
                @endforelse
                </tbody>
                @if ($report['customers']->isNotEmpty())
                    <tfoot>
                    <tr class="row-strong">
                        <td>Toplam</td>
                        @foreach ($buckets as $key => $meta) <td class="num">{{ Money::format($report['buckets'][$key]['amount']) }}</td> @endforeach
                        <td class="num">{{ Money::format($report['total']) }}</td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    @else
        @if ($filters['bucket'] ?? null)
            <p class="muted">Filtre: <strong>{{ $buckets[$filters['bucket']]['label'] }}</strong> ·
                <a href="{{ request()->fullUrlWithQuery(['bucket' => null]) }}">filtreyi kaldır</a></p>
        @endif
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Müşteri</th><th>Poliçe / referans</th><th>Vade</th><th class="num">Gecikme</th><th>Dilim</th><th class="num">Alacak</th><th class="num">Tahsil edilen</th><th class="num">Kalan</th></tr>
                </thead>
                <tbody>
                @forelse ($lines as $l)
                    @php($meta = $buckets[$l->bucket])
                    <tr>
                        <td>{{ $l->customer_name }}</td>
                        <td><a href="{{ route('receivables.show', $l->id) }}">{{ $l->reference_no }}</a>
                            @if ($l->insurance_company) <small class="muted d-block">{{ $l->insurance_company }}</small> @endif
                        </td>
                        <td>{{ \Carbon\Carbon::parse($l->due_date)->format('d.m.Y') }}</td>
                        <td class="num">{{ $l->days > 0 ? $l->days.' gün' : ($l->days === 0 ? 'Bugün' : abs($l->days).' gün var') }}</td>
                        <td><span class="badge level-badge-{{ $meta['level'] }}">{{ $meta['label'] }}</span></td>
                        <td class="num">{{ Money::format($l->amount) }}</td>
                        <td class="num">{{ Money::format($l->collected) }}</td>
                        <td class="num"><strong>{{ Money::format($l->remaining) }}</strong></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Kayıt yok.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <p class="muted small section-gap">
        Gecikme günü = rapor tarihi − vade tarihi. Yalnızca kalan tutar yaşlandırılır; iptal edilen alacaklar dahil değildir.
        Vadesi rapor tarihine eşit olan alacak henüz gecikmiş sayılmaz.
    </p>
@endsection
