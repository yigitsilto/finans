@extends('layouts.app')

@php
    use App\Http\Controllers\CashflowController;
    use App\Services\CashflowService;
    use App\Support\Money;
    $r = $report;
    $neg = fn (string $v) => str_starts_with($v, '-') ? 'negative' : '';
    $periodLabel = $r['from']->isSameDay($r['to'])
        ? $r['from']->format('d.m.Y')
        : $r['from']->format('d.m.Y').' – '.$r['to']->format('d.m.Y');
@endphp

@section('title', 'Nakit Akış Raporu')

@section('content')
    <form method="GET" class="filters card-filters">
        <label>Dönem
            <select name="mode">
                @foreach (CashflowController::MODES as $value => $label)
                    <option value="{{ $value }}" @selected($mode === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Tarih <small class="muted">(gün/hafta/ay için)</small>
            <input type="date" name="date" class="input" value="{{ $anchor->toDateString() }}">
        </label>
        <label>Başlangıç <small class="muted">(özel)</small>
            <input type="date" name="from" class="input" value="{{ request('from', $r['from']->toDateString()) }}">
        </label>
        <label>Bitiş <small class="muted">(özel)</small>
            <input type="date" name="to" class="input" value="{{ request('to', $r['to']->toDateString()) }}">
        </label>
        <label>Kırılım
            <select name="breakdown">
                <option value="none" @selected($breakdown === 'none')>Yok</option>
                @foreach (CashflowService::GRANULARITIES as $value => $label)
                    <option value="{{ $value }}" @selected($breakdown === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn btn-secondary">Göster</button>
    </form>

    <div class="toolbar">
        <h2 class="section-title no-margin">{{ CashflowController::MODES[$mode] }} · {{ $periodLabel }}</h2>
        <div class="actions">
            <a class="btn btn-secondary btn-sm" href="{{ route('exports.download', ['report' => 'cashflow', 'format' => 'xlsx', 'from' => $r['from']->toDateString(), 'to' => $r['to']->toDateString()]) }}">Excel</a>
            <a class="btn btn-secondary btn-sm" href="{{ route('exports.download', ['report' => 'cashflow', 'format' => 'pdf', 'from' => $r['from']->toDateString(), 'to' => $r['to']->toDateString()]) }}">PDF</a>
        </div>
        @if ($prev)
            <div class="actions">
                <a class="btn btn-secondary btn-sm" href="{{ request()->fullUrlWithQuery(['date' => $prev]) }}">‹ Önceki</a>
                <a class="btn btn-secondary btn-sm" href="{{ request()->fullUrlWithQuery(['date' => $next]) }}">Sonraki ›</a>
            </div>
        @endif
    </div>

    @unless ($r['reconciled'])
        <div class="alert alert-danger"><strong>Mutabakat hatası:</strong> formülle hesaplanan bakiyeler hesap bakiyeleriyle eşleşmiyor. Ayrıntı aşağıda.</div>
    @endunless
    @if (str_starts_with($r['net'], '-'))
        <div class="alert alert-warning">Bu dönemde nakit çıkışı nakit girişinden fazla (net {{ Money::format($r['net']) }}).</div>
    @endif

    {{-- A–E özet --}}
    <div class="cf-summary">
        <div class="cf-step">
            <span class="cf-key">A</span>
            <div>
                <span>Başlangıç kullanılabilir nakit</span>
                <strong>{{ Money::format($r['opening']['available']) }}</strong>
                <small>Kasa {{ Money::format($r['opening']['cash']) }} · Banka {{ Money::format($r['opening']['bank']) }} · Blokeli POS {{ Money::format($r['opening']['pos']) }} (ayrı)</small>
            </div>
        </div>
        <div class="cf-step">
            <span class="cf-key">B</span>
            <div><span>Toplam nakit girişleri</span><strong class="positive">{{ Money::format($r['total_inflow']) }}</strong></div>
        </div>
        <div class="cf-step">
            <span class="cf-key">C</span>
            <div><span>Toplam nakit çıkışları</span><strong class="negative">{{ Money::format($r['total_outflow']) }}</strong>
                <small>Kredi taksitleri: anapara {{ Money::format($r['loan_installments']['principal']) }} · faiz {{ Money::format($r['loan_installments']['interest']) }}</small></div>
        </div>
        <div class="cf-step">
            <span class="cf-key">D</span>
            <div><span>Net dönem nakit akışı (B − C)</span><strong class="{{ $neg($r['net']) }}">{{ Money::format($r['net']) }}</strong>
                <small>İşletme {{ Money::format($r['operating_net']) }} · Finansman {{ Money::format($r['financing_net']) }}</small></div>
        </div>
        <div class="cf-step cf-result">
            <span class="cf-key">E</span>
            <div><span>Dönem sonu kullanılabilir nakit</span><strong class="{{ $neg($r['closing_available']) }}">{{ Money::format($r['closing_available']) }}</strong>
                <small>Blokeli POS {{ Money::format($r['closing_pos']) }} · Toplam likidite {{ Money::format($r['closing_total']) }}</small></div>
        </div>
    </div>

    <div class="card cf-bridge">
        <h2 class="card-title">Kullanılabilir nakit köprüsü</h2>
        <table class="table compact">
            <tr><td>A. Başlangıç kullanılabilir nakit</td><td class="num">{{ Money::format($r['opening']['available']) }}</td></tr>
            <tr><td>+ D. Net dönem nakit akışı</td><td class="num {{ $neg($r['net']) }}">{{ Money::format($r['net']) }}</td></tr>
            <tr><td>− POS blokesindeki artış <small class="muted">(POS'a giren − bankaya aktarılan)</small></td><td class="num">{{ Money::format(\Brick\Math\BigDecimal::of($r['pos_change'])->negated()) }}</td></tr>
            @if ($r['period_openings_available'] !== '0.00')
                <tr><td>+ Dönem içi açılış/devir kayıtları <small class="muted">(nakit akışı değil)</small></td><td class="num">{{ Money::format($r['period_openings_available']) }}</td></tr>
            @endif
            <tr class="row-strong"><td>= E. Dönem sonu kullanılabilir nakit</td><td class="num">{{ Money::format($r['closing_available']) }}</td></tr>
        </table>
    </div>

    <div class="cf-columns">
        @foreach (['inflows' => ['Nakit girişleri', $r['total_inflow']], 'outflows' => ['Nakit çıkışları', $r['total_outflow']]] as $key => [$title, $total])
            <div class="table-wrap">
                <table class="table compact">
                    <thead><tr><th>{{ $title }}</th><th class="num">Tutar</th></tr></thead>
                    <tbody>
                    @forelse ($r[$key] as $activity)
                        <tr class="row-activity"><td>{{ $activity['label'] }}</td><td class="num">{{ Money::format($activity['total']) }}</td></tr>
                        @foreach ($activity['groups'] as $group)
                            <tr class="row-group"><td>{{ $group['name'] }}</td><td class="num">{{ Money::format($group['total']) }}</td></tr>
                            @foreach ($group['items'] as $item)
                                <tr class="row-item"><td>{{ $item['name'] }}</td><td class="num">{{ Money::format($item['total']) }}</td></tr>
                            @endforeach
                        @endforeach
                    @empty
                        <tr><td colspan="2" class="muted">Bu dönemde kayıt yok.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr class="row-strong"><td>Toplam</td><td class="num">{{ Money::format($total) }}</td></tr></tfoot>
                </table>
            </div>
        @endforeach
    </div>

    @if ($series)
        <h2 class="section-title">{{ CashflowService::GRANULARITIES[$breakdown] }} kırılım</h2>
        @unless ($series['carry_over_ok'])
            <div class="alert alert-danger">Devir mutabakatı hatası: en az bir dönemin açılışı önceki dönemin kapanışıyla eşleşmiyor.</div>
        @endunless
        <div class="table-wrap">
            <table class="table compact">
                <thead>
                <tr><th>Dönem</th><th class="num">Açılış (kullanılabilir)</th><th class="num">Giriş</th><th class="num">Çıkış</th><th class="num">Net</th><th class="num">POS blokesi değişimi</th><th class="num">Açılış kaydı</th><th class="num">Kapanış (kullanılabilir)</th><th class="num">Blokeli POS</th><th>Devir</th></tr>
                </thead>
                <tbody>
                @foreach ($series['rows'] as $row)
                    <tr>
                        <td>{{ $row['from']->isSameDay($row['to']) ? $row['from']->format('d.m.Y') : $row['from']->format('d.m').' – '.$row['to']->format('d.m.Y') }}</td>
                        <td class="num">{{ Money::format($row['opening_available']) }}</td>
                        <td class="num">{{ Money::format($row['inflow']) }}</td>
                        <td class="num">{{ Money::format($row['outflow']) }}</td>
                        <td class="num {{ $neg($row['net']) }}">{{ Money::format($row['net']) }}</td>
                        <td class="num">{{ Money::format($row['pos_change']) }}</td>
                        <td class="num">{{ $row['period_openings'] === '0.00' ? '—' : Money::format($row['period_openings']) }}</td>
                        <td class="num"><strong>{{ Money::format($row['closing_available']) }}</strong></td>
                        <td class="num">{{ Money::format($row['closing_pos']) }}</td>
                        <td>{!! $row['carry_over_ok'] ? '<span class="badge badge-success">✓</span>' : '<span class="badge badge-danger">Eşleşmiyor</span>' !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2 class="section-title">Hesap bazlı mutabakat</h2>
    <div class="table-wrap">
        <table class="table compact">
            <thead>
            <tr><th>Hesap</th><th>Tür</th><th class="num">Açılış</th><th class="num">+ Giriş</th><th class="num">− Çıkış</th><th class="num">+ Transfer gelen</th><th class="num">− Transfer giden</th><th class="num">Kapanış</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($r['accounts'] as $row)
                <tr>
                    <td><a href="{{ route('accounts.show', ['account' => $row['account'], 'from' => $r['from']->toDateString(), 'to' => $r['to']->toDateString()]) }}">{{ $row['account']->name }}</a></td>
                    <td>{{ $row['account']->type->label() }}</td>
                    <td class="num">{{ Money::format($row['opening']) }}@if ($row['openings'] !== '0.00')<small class="muted d-block">+ açılış {{ Money::format($row['openings']) }}</small>@endif</td>
                    <td class="num">{{ Money::format($row['inflow']) }}</td>
                    <td class="num">{{ Money::format($row['outflow']) }}</td>
                    <td class="num">{{ Money::format($row['transfer_in']) }}</td>
                    <td class="num">{{ Money::format($row['transfer_out']) }}</td>
                    <td class="num"><strong>{{ Money::format($row['closing']) }}</strong></td>
                    <td>{!! $row['ok'] ? '<span class="badge badge-success">✓</span>' : '<span class="badge badge-danger">Hata</span>' !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <h2 class="section-title">Mutabakat kontrolleri</h2>
    <div class="table-wrap">
        <table class="table compact">
            <thead><tr><th>Kontrol</th><th class="num">Beklenen</th><th class="num">Gerçekleşen</th><th>Sonuç</th></tr></thead>
            <tbody>
            @foreach ($r['checks'] as $check)
                <tr>
                    <td>{{ $check['label'] }}</td>
                    <td class="num">{{ is_numeric($check['expected']) ? Money::format($check['expected']) : $check['expected'] }}</td>
                    <td class="num">{{ is_numeric($check['actual']) ? Money::format($check['actual']) : $check['actual'] }}</td>
                    <td>{!! $check['ok'] ? '<span class="badge badge-success">Eşleşiyor</span>' : '<span class="badge badge-danger">Eşleşmiyor</span>' !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <p class="muted small section-gap">
        Transferler (POS → banka dahil) hesaplar arası harekettir, nakit girişi/çıkışı sayılmaz. POS tahsilatları giriş olarak sayılır,
        ancak bankaya aktarılana kadar kullanılabilir nakde dahil edilmez. Açılış/devir kayıtları nakit akışı değildir. İptaller ters kayıtla
        kendi tarihlerinde yansır.
    </p>
@endsection
