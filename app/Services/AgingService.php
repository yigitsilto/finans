<?php

namespace App\Services;

use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alacak yaşlandırma.
 *
 *   gecikme günü = rapor tarihi − vade tarihi
 *
 * Yalnızca rapor tarihindeki KALAN tutar yaşlandırılır:
 *   kalan = alacak − (tarihi ≤ rapor tarihi olan tahsilatlar ve iptalleri)
 * Böylece geçmiş bir tarih seçildiğinde, o tarihten sonraki tahsilatlar ve alacaklar rapora girmez.
 *
 * Sınır kararı (PRD §15 tablosu; ürün sahibi onayladı): vade tarihi rapor tarihine eşitse (0 gün) alacak henüz
 * GECİKMİŞ SAYILMAZ, "Vadesi Gelmemiş"tir. 0–30 dilimi fiilen 1–30 gün gecikmeyi kapsar.
 * Bu karar yalnızca bucket() içindedir.
 */
class AgingService
{
    public const BUCKETS = [
        'not_due' => ['label' => 'Vadesi Gelmemiş', 'risk' => 'Normal', 'level' => 'info'],
        'd0_30' => ['label' => '0–30 Gün', 'risk' => 'Hafif gecikme', 'level' => 'low'],
        'd31_60' => ['label' => '31–60 Gün', 'risk' => 'Orta risk', 'level' => 'medium'],
        'd61_90' => ['label' => '61–90 Gün', 'risk' => 'Yüksek risk', 'level' => 'high'],
        'd90_plus' => ['label' => '90+ Gün', 'risk' => 'Kritik risk', 'level' => 'critical'],
    ];

    /** Gecikme gününe göre yaşlandırma dilimi. Tek doğruluk kaynağı. */
    public static function bucket(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'not_due',
            $daysOverdue <= 30 => 'd0_30',
            $daysOverdue <= 60 => 'd31_60',
            $daysOverdue <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }

    /**
     * Rapor tarihindeki kalan tutarı olan alacaklar, gecikme günü ve dilimiyle.
     *
     * @return Collection<int, object{id: int, customer_id: int, customer_name: string, reference_no: string,
     *     insurance_company: ?string, amount: string, collected: string, remaining: string, issue_date: string,
     *     due_date: string, days: int, bucket: string}>
     */
    public function lines(CarbonImmutable $reportDate, ?int $customerId = null): Collection
    {
        $date = $reportDate->toDateString();

        $rows = DB::select(<<<'SQL'
            SELECT r.id, r.customer_id, c.name AS customer_name, r.reference_no, r.insurance_company,
                   r.amount::text AS amount, r.issue_date::text AS issue_date, r.due_date::text AS due_date,
                   COALESCE(col.collected, 0)::numeric(18,2)::text AS collected,
                   (r.amount - COALESCE(col.collected, 0))::numeric(18,2)::text AS remaining,
                   (CAST(? AS date) - r.due_date) AS days
            FROM receivables r
            JOIN customers c ON c.id = r.customer_id
            LEFT JOIN LATERAL (
                SELECT SUM(t.amount) AS collected FROM transactions t
                WHERE t.receivable_id = r.id AND t.date <= CAST(? AS date)
            ) col ON TRUE
            WHERE r.issue_date <= CAST(? AS date)
              AND (r.cancelled_at IS NULL OR r.cancelled_at::date > CAST(? AS date))
              AND (CAST(? AS bigint) IS NULL OR r.customer_id = CAST(? AS bigint))
              AND r.amount - COALESCE(col.collected, 0) > 0
            ORDER BY (CAST(? AS date) - r.due_date) DESC, r.id
        SQL, [$date, $date, $date, $date, $customerId, $customerId, $date]);

        return collect($rows)->map(function ($row) {
            $row->days = (int) $row->days;
            $row->bucket = self::bucket($row->days);

            return $row;
        });
    }

    /**
     * Dilim toplamları, müşteri bazlı dağılım ve genel toplam.
     *
     * @return array{date: CarbonImmutable, buckets: array<string, array{amount: string, count: int, percent: ?string}>,
     *     total: string, overdue: string, count: int, customers: Collection, lines: Collection}
     */
    public function report(CarbonImmutable $reportDate, ?int $customerId = null): array
    {
        $lines = $this->lines($reportDate, $customerId);

        $zero = fn () => array_fill_keys(array_keys(self::BUCKETS), BigDecimal::zero());
        $bucketTotals = $zero();
        $counts = array_fill_keys(array_keys(self::BUCKETS), 0);
        $customers = [];

        foreach ($lines as $line) {
            $bucketTotals[$line->bucket] = $bucketTotals[$line->bucket]->plus($line->remaining);
            $counts[$line->bucket]++;

            $customers[$line->customer_id] ??= ['id' => $line->customer_id, 'name' => $line->customer_name, 'buckets' => $zero(), 'total' => BigDecimal::zero()];
            $customers[$line->customer_id]['buckets'][$line->bucket] = $customers[$line->customer_id]['buckets'][$line->bucket]->plus($line->remaining);
            $customers[$line->customer_id]['total'] = $customers[$line->customer_id]['total']->plus($line->remaining);
        }

        $total = array_reduce($bucketTotals, fn ($c, $v) => $c->plus($v), BigDecimal::zero());

        $buckets = [];
        foreach ($bucketTotals as $key => $amount) {
            $buckets[$key] = [
                'amount' => (string) Money::of($amount),
                'count' => $counts[$key],
                'percent' => $total->isPositive() ? (string) $amount->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HALF_UP) : null,
            ];
        }

        $customerRows = collect($customers)
            ->map(fn ($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'buckets' => array_map(fn ($v) => (string) Money::of($v), $c['buckets']),
                'total' => (string) Money::of($c['total']),
                'overdue' => (string) Money::of($c['total']->minus($c['buckets']['not_due'])),
            ])
            // En riskli müşteri üstte: önce 90+ tutarı, sonra toplam vadesi geçmiş, sonra toplam (BigDecimal karşılaştırma).
            ->sort(fn ($a, $b) => BigDecimal::of($b['buckets']['d90_plus'])->compareTo($a['buckets']['d90_plus'])
                ?: BigDecimal::of($b['overdue'])->compareTo($a['overdue'])
                ?: BigDecimal::of($b['total'])->compareTo($a['total']))
            ->values();

        return [
            'date' => $reportDate,
            'buckets' => $buckets,
            'total' => (string) Money::of($total),
            'overdue' => (string) Money::of($total->minus($bucketTotals['not_due'])),
            'count' => $lines->count(),
            'customers' => $customerRows,
            'lines' => $lines,
        ];
    }
}
