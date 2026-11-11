@extends('layouts.app')

@php
    use App\Support\Money;
    $c = $data['cards'];
    $neg = fn (string $v) => str_starts_with($v, '-') ? 'negative' : '';
    $cards = [
        ['Toplam kullanılabilir nakit', $c['available'], 'Kasa + banka', route('accounts.index', ['type' => 'available'])],
        ['Toplam banka bakiyesi', $c['bank'], null, route('accounts.index', ['type' => 'bank'])],
        ['Kasa bakiyesi', $c['cash'], null, route('accounts.index', ['type' => 'cash'])],
        ['Blokeli POS', $c['pos_blocked'], 'Kullanılabilir nakde dahil değil', route('pos.index')],
        ['Bu ay toplam giriş', $c['month_inflow'], null, route('cashflow.index')],
        ['Bu ay toplam çıkış', $c['month_outflow'], null, route('cashflow.index')],
        ['Net nakit akışı (bu ay)', $c['month_net'], null, route('cashflow.index')],
        ['Toplam müşteri alacağı', $c['receivables_total'], 'Kalan tutar', route('receivables.index')],
        ['Vadesi geçmiş alacak', $c['receivables_overdue'], null, route('receivables.index', ['status' => 'overdue'])],
        ['90+ gün kritik alacak', $c['receivables_critical'], null, route('aging.index', ['view' => 'detail', 'bucket' => 'd90_plus'])],
    ];
    $highlight = ['Vadesi geçmiş alacak', '90+ gün kritik alacak'];
@endphp

@section('title', 'Dashboard')

@section('content')
    @unless ($hasAccounts)
        <div class="alert alert-info">
            Henüz hesap tanımlı değil. Rakamlar gerçek kayıtlardan hesaplanır; başlamak için
            @can('write-finance') <a href="{{ route('accounts.create') }}">kasa veya banka hesabı ekleyin</a>. @else bir finans kullanıcısının hesap eklemesi gerekir. @endcan
        </div>
    @endunless

    @unless ($data['reconciled'])
        <div class="alert alert-danger"><strong>Mutabakat hatası:</strong> bu ayın nakit akışı hesap bakiyeleriyle eşleşmiyor.
            <a href="{{ route('cashflow.index') }}">Nakit akış raporunu inceleyin.</a></div>
    @endunless

    <div class="dash-cards">
        @foreach ($cards as [$label, $value, $hint, $url])
            <a class="stat dash-card" href="{{ $url }}">
                <span>{{ $label }}</span>
                <strong class="{{ $neg($value) }} {{ in_array($label, $highlight, true) && $value !== '0.00' ? 'negative' : '' }}">{{ Money::format($value) }}</strong>
                @if ($hint) <small>{{ $hint }}</small> @endif
            </a>
        @endforeach
    </div>

    <div class="dash-grid">
        <section class="card">
            <h2 class="card-title">Uyarılar</h2>
            @include('partials.alerts', ['alerts' => collect($data['alerts'])->take(6)])
            <a class="small" href="{{ route('analysis.index') }}">Finansal analiz ›</a>
        </section>
        <section class="card">
            <h2 class="card-title">Aylık nakit giriş / çıkış</h2>
            <div class="chart-box"><canvas id="chart-flows" aria-label="Aylık nakit giriş çıkış grafiği" role="img"></canvas></div>
        </section>
        <section class="card">
            <h2 class="card-title">Nakit bakiye trendi (ay sonu)</h2>
            <div class="chart-box"><canvas id="chart-trend" aria-label="Nakit bakiye trendi" role="img"></canvas></div>
        </section>
        <section class="card">
            <h2 class="card-title">Alacak yaşlandırma dağılımı</h2>
            <div class="chart-box"><canvas id="chart-aging" aria-label="Alacak yaşlandırma dağılımı" role="img"></canvas></div>
        </section>
        <section class="card">
            <h2 class="card-title">Gider kategorisi dağılımı (bu ay)</h2>
            <div class="chart-box"><canvas id="chart-expenses" aria-label="Gider kategorisi dağılımı" role="img"></canvas></div>
            <p class="muted small chart-empty" id="expenses-empty" hidden>Bu ay nakit çıkışı yok.</p>
        </section>
    </div>

    <p class="muted small">Veriler {{ \Carbon\Carbon::parse($data['date'])->format('d.m.Y') }} itibarıyla gerçek kayıtlardan hesaplanmıştır.</p>
@endsection

@push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        // Grafikler dashboard API'sinden beslenir. Tutarlar API'den string gelir;
        // burada yalnızca çizim için sayıya çevrilir, hiçbir finansal hesap yapılmaz.
        (function () {
            const tl = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY' });
            const num = (v) => Number(v);
            const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            const money = { callbacks: { label: (ctx) => `${ctx.dataset.label || ctx.label}: ${tl.format(ctx.raw)}` } };
            const yMoney = { ticks: { callback: (v) => tl.format(v).replace(',00', '') } };

            fetch(@json(route('api.dashboard')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(({ charts }) => {
                    if (typeof Chart === 'undefined') return;
                    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

                    new Chart(document.getElementById('chart-flows'), {
                        type: 'bar',
                        data: {
                            labels: charts.monthly_flows.map((m) => m.label),
                            datasets: [
                                { label: 'Giriş', data: charts.monthly_flows.map((m) => num(m.inflow)), backgroundColor: css('--success') },
                                { label: 'Çıkış', data: charts.monthly_flows.map((m) => num(m.outflow)), backgroundColor: css('--danger') },
                            ],
                        },
                        options: { maintainAspectRatio: false, plugins: { tooltip: money }, scales: { y: yMoney } },
                    });

                    new Chart(document.getElementById('chart-trend'), {
                        type: 'line',
                        data: {
                            labels: charts.balance_trend.map((m) => m.label),
                            datasets: [
                                { label: 'Kullanılabilir nakit', data: charts.balance_trend.map((m) => num(m.available)), borderColor: css('--primary'), backgroundColor: css('--primary'), tension: 0.2 },
                                { label: 'Blokeli POS', data: charts.balance_trend.map((m) => num(m.pos_blocked)), borderColor: css('--muted'), backgroundColor: css('--muted'), borderDash: [4, 4], tension: 0.2 },
                            ],
                        },
                        options: { maintainAspectRatio: false, plugins: { tooltip: money }, scales: { y: yMoney } },
                    });

                    const riskColors = { info: css('--risk-info'), low: css('--risk-low'), medium: css('--risk-medium'), high: css('--risk-high'), critical: css('--risk-critical') };
                    new Chart(document.getElementById('chart-aging'), {
                        type: 'bar',
                        data: {
                            labels: charts.aging.map((b) => b.label),
                            datasets: [{ label: 'Kalan alacak', data: charts.aging.map((b) => num(b.amount)), backgroundColor: charts.aging.map((b) => riskColors[b.level]) }],
                        },
                        options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: money }, scales: { y: yMoney } },
                    });

                    if (charts.expenses.length === 0) {
                        document.getElementById('expenses-empty').hidden = false;
                        document.getElementById('chart-expenses').parentElement.hidden = true;
                    } else {
                        const palette = ['#12355b', '#2f6690', '#3a7ca5', '#81c3d7', '#9a6700', '#c2560c', '#b42318', '#6b7685'];
                        new Chart(document.getElementById('chart-expenses'), {
                            type: 'doughnut',
                            data: {
                                labels: charts.expenses.map((e) => e.label),
                                datasets: [{ data: charts.expenses.map((e) => num(e.amount)), backgroundColor: palette }],
                            },
                            options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' }, tooltip: money } },
                        });
                    }
                })
                .catch(() => {
                    document.querySelectorAll('.chart-box').forEach((el) => {
                        el.innerHTML = '<p class="muted small">Grafik verisi yüklenemedi. Sayfayı yenileyin.</p>';
                    });
                });
        })();
    </script>
@endpush
