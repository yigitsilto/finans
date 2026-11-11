<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Finansal analiz ve otomatik uyarılar (PRD §19).
 * Seviyeler: critical (kritik), warning (dikkat), info (bilgi).
 */
class FinancialAnalysisService
{
    public const LEVELS = ['critical' => 'Kritik', 'warning' => 'Dikkat', 'info' => 'Bilgi'];

    public function __construct(
        private readonly BalanceService $balances,
        private readonly CashflowService $cashflow,
        private readonly AgingService $aging,
        private readonly ExpenseService $expenses,
        private readonly ObligationService $obligations,
    ) {}

    /**
     * Tüm analiz: likidite, oranlar ve uyarılar.
     *
     * @return array<string, mixed>
     */
    public function analyze(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        $summary = $this->balances->summary($today);
        $month = $this->cashflow->report($monthStart, $today);
        $aging = $this->aging->report($today);
        $ratios = $this->expenses->analysis($monthStart, $today);

        $horizonDays = (int) config('finance.liquidity_horizon_days');
        $horizonEnd = $today->addDays($horizonDays);
        $obligations = $this->obligations->upcoming($horizonEnd, $today);
        $obligationTotal = ObligationService::total($obligations);
        $available = BigDecimal::of($summary['available']);
        $coverage = $available->isPositive()
            ? (string) BigDecimal::of($obligationTotal)->multipliedBy(100)->dividedBy($available, 2, RoundingMode::HALF_UP)
            : null;

        $alerts = collect();

        // 1) Mutabakat
        if (! $month['reconciled']) {
            $alerts->push($this->alert('critical', 'Mutabakat hatası', 'Bu ayın nakit akışı hesap bakiyeleriyle eşleşmiyor. Nakit akış raporundaki kontrolleri inceleyin.', route('cashflow.index')));
        }

        // 2) Likidite riski
        if (BigDecimal::of($obligationTotal)->isGreaterThan($available)) {
            $alerts->push($this->alert('critical', 'Likidite riski tespit edildi.',
                "Önümüzdeki {$horizonDays} gündeki zorunlu ödemeler (".Money::format($obligationTotal).') kullanılabilir nakdi ('.Money::format($available).') aşıyor. Açık: '.Money::format(BigDecimal::of($obligationTotal)->minus($available)).'.',
                route('analysis.index')));
        } elseif ($coverage !== null && BigDecimal::of($coverage)->isGreaterThanOrEqualTo(config('finance.liquidity_warning_percent'))) {
            $alerts->push($this->alert('warning', 'Likidite sıkışıyor',
                "Önümüzdeki {$horizonDays} gündeki zorunlu ödemeler kullanılabilir nakdin %".str_replace('.', ',', $coverage)."'ine ulaştı.",
                route('analysis.index')));
        }

        // 3) Gecikmiş zorunlu ödemeler
        $overdueLoans = $obligations->where('type', 'loan')->where('overdue', true);
        if ($overdueLoans->isNotEmpty()) {
            $alerts->push($this->alert('critical', 'Gecikmiş kredi taksiti', $overdueLoans->count().' kredi taksiti vadesinde ödenmedi ('.Money::format(ObligationService::total($overdueLoans)).').', route('loans.index')));
        }
        $overdueExpenses = $obligations->where('type', 'expense')->where('overdue', true);
        if ($overdueExpenses->isNotEmpty()) {
            $alerts->push($this->alert('warning', 'Gecikmiş gider ödemesi', $overdueExpenses->count().' tekrarlayan gider vadesinde ödenmedi ('.Money::format(ObligationService::total($overdueExpenses)).').', route('expenses.index')));
        }

        // 4) Kritik alacak
        if ($aging['buckets']['d90_plus']['count'] > 0) {
            $alerts->push($this->alert('critical', 'Kritik tahsilat riski bulunmaktadır.',
                $aging['buckets']['d90_plus']['count'].' alacak 90 günü aştı ('.Money::format($aging['buckets']['d90_plus']['amount']).').',
                route('aging.index', ['view' => 'detail', 'bucket' => 'd90_plus'])));
        }
        if (BigDecimal::of($aging['overdue'])->isPositive()) {
            $alerts->push($this->alert('warning', 'Vadesi geçmiş alacak', 'Toplam vadesi geçmiş alacak: '.Money::format($aging['overdue']).'.', route('receivables.index', ['status' => 'overdue'])));
        }

        // 5) Negatif nakit akışı (bu ay)
        if (BigDecimal::of($month['total_outflow'])->isGreaterThan($month['total_inflow'])) {
            $alerts->push($this->alert('warning', 'Negatif nakit akışı', 'Bu ay nakit çıkışı ('.Money::format($month['total_outflow']).') nakit girişini ('.Money::format($month['total_inflow']).') aşıyor.', route('cashflow.index')));
        }

        // 6) Eksi bakiyeli hesaplar (KMH)
        $balances = $this->balances->balances($today);
        $negative = Account::all()->filter(fn ($a) => BigDecimal::of($balances[$a->id] ?? '0')->isNegative());
        if ($negative->isNotEmpty()) {
            $alerts->push($this->alert('warning', 'Eksi bakiyeli hesap', $negative->pluck('name')->join(', ').' eksi bakiyede.', route('accounts.index')));
        }

        // 7) Gider oranları (bilgi; sabit gider oranı eşiği aşarsa dikkat)
        if ($ratios['fixed_ratio'] !== null) {
            $level = BigDecimal::of($ratios['fixed_ratio'])->isGreaterThan(config('finance.fixed_expense_warning_percent')) ? 'warning' : 'info';
            $alerts->push($this->alert($level, 'Gider oranları (bu ay)',
                'Sabit gider oranı %'.str_replace('.', ',', $ratios['fixed_ratio']).', değişken gider oranı %'.str_replace('.', ',', $ratios['variable_ratio']).' (toplam nakit çıkışına göre).',
                route('expenses.index')));
        }

        // 8) Aktarılmayı bekleyen POS
        if (BigDecimal::of($summary['pos_blocked'])->isPositive()) {
            $alerts->push($this->alert('info', 'Blokeli POS', Money::format($summary['pos_blocked']).' POS tahsilatı bankaya geçmeyi bekliyor (kullanılabilir nakde dahil değil).', route('pos.index')));
        }

        $order = array_flip(array_keys(self::LEVELS));

        return [
            'today' => $today,
            'summary' => $summary,
            'month' => $month,
            'aging' => $aging,
            'ratios' => $ratios,
            'horizon_days' => $horizonDays,
            'obligations' => $obligations,
            'obligation_total' => $obligationTotal,
            'coverage_percent' => $coverage,
            'liquidity_gap' => (string) Money::of(BigDecimal::of($obligationTotal)->minus($available)),
            'alerts' => $alerts->sortBy(fn ($a) => $order[$a['level']])->values(),
        ];
    }

    /** @return array{level: string, title: string, message: string, url: ?string} */
    private function alert(string $level, string $title, string $message, ?string $url = null): array
    {
        return compact('level', 'title', 'message', 'url');
    }
}
