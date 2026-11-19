<?php

namespace App\Services;

use App\Support\Money;
use App\Support\Sql;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard verisi. Tüm tutarlar string (2 basamak) olarak döner; grafiklerde yalnızca
 * görüntüleme amacıyla sayıya çevrilir, hiçbir hesap tarayıcıda yapılmaz.
 */
class DashboardService
{
    public const TREND_MONTHS = 12;

    public function __construct(
        private readonly FinancialAnalysisService $analysis,
        private readonly BalanceService $balances,
    ) {}

    /** @return array<string, mixed> */
    public function data(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $a = $this->analysis->analyze($today);

        $cards = [
            'available' => $a['summary']['available'],
            'bank' => $a['summary']['bank'],
            'cash' => $a['summary']['cash'],
            'pos_blocked' => $a['summary']['pos_blocked'],
            'month_inflow' => $a['month']['total_inflow'],
            'month_outflow' => $a['month']['total_outflow'],
            'month_net' => $a['month']['net'],
            'receivables_total' => $a['aging']['total'],
            'receivables_overdue' => $a['aging']['overdue'],
            'receivables_critical' => $a['aging']['buckets']['d90_plus']['amount'],
        ];

        return [
            'date' => $today->toDateString(),
            'cards' => $cards,
            'reconciled' => $a['month']['reconciled'],
            'alerts' => $a['alerts']->all(),
            'charts' => [
                'monthly_flows' => $this->monthlyFlows($today),
                'balance_trend' => $this->balanceTrend($today),
                'aging' => collect(AgingService::BUCKETS)->map(fn ($meta, $key) => [
                    'label' => $meta['label'], 'level' => $meta['level'], 'amount' => $a['aging']['buckets'][$key]['amount'],
                ])->values()->all(),
                'expenses' => $this->expenseDistribution($a['ratios']['by_category']),
            ],
        ];
    }

    /** Son 12 ayın aylık giriş/çıkış toplamları (tek sorgu). */
    private function monthlyFlows(CarbonImmutable $today): array
    {
        $start = $today->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        $month = Sql::yearMonth('date');
        $rows = collect(DB::select(<<<SQL
            SELECT {$month} AS month,
                CAST(COALESCE(SUM(CASE WHEN type = 'inflow' THEN amount END), 0) AS DECIMAL(18,2)) AS inflow,
                CAST(COALESCE(SUM(CASE WHEN type = 'outflow' THEN amount END), 0) AS DECIMAL(18,2)) AS outflow
            FROM transactions
            WHERE type IN ('inflow', 'outflow') AND date BETWEEN ? AND ?
            GROUP BY {$month}
        SQL, [$start->toDateString(), $today->toDateString()]))->keyBy('month');

        $result = [];
        for ($m = $start; $m->lte($today); $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $result[] = [
                'label' => $m->locale('tr')->translatedFormat('M Y'),
                'inflow' => $rows[$key]->inflow ?? '0.00',
                'outflow' => $rows[$key]->outflow ?? '0.00',
            ];
        }

        return $result;
    }

    /** Ay sonu kullanılabilir nakit ve blokeli POS (içinde bulunulan ay için bugün). */
    private function balanceTrend(CarbonImmutable $today): array
    {
        $result = [];
        $start = $today->startOfMonth()->subMonths(self::TREND_MONTHS - 1);
        for ($m = $start; $m->lte($today); $m = $m->addMonth()) {
            $point = $m->endOfMonth()->gt($today) ? $today : $m->endOfMonth()->startOfDay();
            $s = $this->balances->summary($point);
            $result[] = [
                'label' => $m->locale('tr')->translatedFormat('M Y'),
                'available' => $s['available'],
                'pos_blocked' => $s['pos_blocked'],
            ];
        }

        return $result;
    }

    /** Bu ayın nakit çıkışlarının grup bazlı dağılımı (ilk 7 grup + diğer). */
    private function expenseDistribution($rows): array
    {
        $groups = collect($rows)->groupBy('group_name')
            ->map(fn ($items, $name) => ['label' => $name, 'amount' => $items->reduce(fn ($c, $r) => $c->plus($r->total), BigDecimal::zero())])
            ->filter(fn ($g) => $g['amount']->isPositive())
            ->sort(fn ($a, $b) => $b['amount']->compareTo($a['amount']))
            ->values();

        $top = $groups->take(7);
        $rest = $groups->slice(7)->reduce(fn ($c, $g) => $c->plus($g['amount']), BigDecimal::zero());
        if ($rest->isPositive()) {
            $top->push(['label' => 'Diğer', 'amount' => $rest]);
        }

        return $top->map(fn ($g) => ['label' => $g['label'], 'amount' => (string) Money::of($g['amount'])])->values()->all();
    }
}
