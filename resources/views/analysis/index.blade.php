@extends('layouts.app')

@php
    use App\Support\Money;
    $pct = fn (?string $v) => $v === null ? '—' : '%'.str_replace('.', ',', $v);
    $gapPositive = ! str_starts_with($a['liquidity_gap'], '-') && $a['liquidity_gap'] !== '0.00';
@endphp

@section('title', 'Finansal Analiz')

@section('content')
    <h2 class="section-title no-margin">Uyarılar</h2>
    <div class="section-gap">
        @include('partials.alerts', ['alerts' => $a['alerts']])
    </div>

    <h2 class="section-title">Likidite</h2>
    <form method="GET" class="filters card-filters">
        <label>Ufuk
            <select name="horizon" onchange="this.form.submit()">
                @foreach ([7, 15, 30, 60, 90] as $days)
                    <option value="{{ $days }}" @selected($a['horizon_days'] === $days)>{{ $days }} gün</option>
                @endforeach
            </select>
        </label>
    </form>
    <div class="stat-grid">
        <div class="stat"><span>Kullanılabilir nakit</span><strong>{{ Money::format($a['summary']['available']) }}</strong><small>Blokeli POS hariç ({{ Money::format($a['summary']['pos_blocked']) }})</small></div>
        <div class="stat"><span>{{ $a['horizon_days'] }} günlük zorunlu ödemeler</span><strong>{{ Money::format($a['obligation_total']) }}</strong><small>{{ $a['obligations']->count() }} kalem · gecikmişler dahil</small></div>
        <div class="stat"><span>Ödemelerin nakde oranı</span><strong>{{ $pct($a['coverage_percent']) }}</strong></div>
        <div class="stat"><span>{{ $gapPositive ? 'Likidite açığı' : 'Ödemeler sonrası kalan' }}</span>
            <strong class="{{ $gapPositive ? 'negative' : 'positive' }}">{{ Money::format(\Brick\Math\BigDecimal::of($a['liquidity_gap'])->abs()) }}</strong></div>
    </div>

    <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Vade</th><th>Ödeme</th><th>Tür</th><th class="num">Tutar</th></tr></thead>
            <tbody>
            @forelse ($a['obligations'] as $o)
                <tr>
                    <td>{{ $o['due_date']->format('d.m.Y') }} @if ($o['overdue']) <span class="badge badge-danger">Gecikti</span> @endif</td>
                    <td><a href="{{ $o['url'] }}">{{ $o['label'] }}</a></td>
                    <td>{{ $o['type'] === 'loan' ? 'Kredi taksiti' : 'Tekrarlayan gider' }}</td>
                    <td class="num">{{ Money::format($o['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">Bu ufukta zorunlu ödeme yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="muted small">Zorunlu ödemeler: ödenmemiş kredi taksitleri ve tekrarlayan giderler (planlanan tutarlarla). Gecikmiş giderler en fazla {{ \App\Services\ObligationService::OVERDUE_LOOKBACK_MONTHS }} ay geriye bakılarak alınır. Beklenen tahsilatlar hesaba katılmaz.</p>

    <h2 class="section-title">Bu ayın oranları</h2>
    <div class="stat-grid">
        <div class="stat"><span>Sabit gider oranı</span><strong>{{ $pct($a['ratios']['fixed_ratio']) }}</strong><small>Sabit gider {{ Money::format($a['ratios']['fixed']) }} / toplam çıkış {{ Money::format($a['ratios']['total_outflow']) }}</small></div>
        <div class="stat"><span>Değişken gider oranı</span><strong>{{ $pct($a['ratios']['variable_ratio']) }}</strong><small>Değişken gider {{ Money::format($a['ratios']['variable']) }}</small></div>
        <div class="stat"><span>Net nakit akışı</span><strong class="{{ str_starts_with($a['month']['net'], '-') ? 'negative' : '' }}">{{ Money::format($a['month']['net']) }}</strong>
            <small>İşletme {{ Money::format($a['month']['operating_net']) }} · Finansman {{ Money::format($a['month']['financing_net']) }}</small></div>
        <div class="stat"><span>Vadesi geçmiş alacak</span><strong>{{ Money::format($a['aging']['overdue']) }}</strong><small>90+ gün: {{ Money::format($a['aging']['buckets']['d90_plus']['amount']) }}</small></div>
    </div>
@endsection
