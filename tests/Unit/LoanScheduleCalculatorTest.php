<?php

namespace Tests\Unit;

use App\Services\LoanScheduleCalculator;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class LoanScheduleCalculatorTest extends TestCase
{
    private function sum(array $rows, string $field): string
    {
        return (string) array_reduce($rows, fn ($c, $r) => $c->plus($r[$field]), BigDecimal::of('0.00'));
    }

    public function test_annuity_matches_standard_pmt_formula(): void
    {
        // PMT(%1, 12, 100.000) = 8.884,88
        $rows = (new LoanScheduleCalculator)->annuity('100000.00', '12', 12, CarbonImmutable::parse('2026-02-15'));

        $this->assertCount(12, $rows);
        $this->assertSame('1000.00', $rows[0]['interest'], 'İlk ay faizi = 100.000 × %1');
        $this->assertSame('7884.88', $rows[0]['principal']);
        $this->assertSame('8884.88', (string) BigDecimal::of($rows[5]['principal'])->plus($rows[5]['interest']));
        $this->assertSame('100000.00', $this->sum($rows, 'principal'), 'Anapara toplamı kredi tutarına eşit olmalı');
        $this->assertSame('6618.53', $this->sum($rows, 'interest'));
        // Son taksit yuvarlama farkını kapatır: 11 × 8.884,88 + 8.884,85 = 106.618,53
        $last = end($rows);
        $this->assertSame('8884.85', (string) BigDecimal::of($last['principal'])->plus($last['interest']));
    }

    public function test_zero_interest_spreads_rounding_to_last_installment(): void
    {
        $rows = (new LoanScheduleCalculator)->annuity('100000.00', '0', 3, CarbonImmutable::parse('2026-02-15'));

        $this->assertSame(['33333.33', '33333.33', '33333.34'], array_column($rows, 'principal'));
        $this->assertSame(['0.00', '0.00', '0.00'], array_column($rows, 'interest'));
    }

    public function test_principal_always_sums_exactly_for_awkward_inputs(): void
    {
        $calc = new LoanScheduleCalculator;
        foreach ([['1234567.89', '47.85', 36], ['999.99', '3.3333', 7], ['5000000.00', '68', 120], ['10.00', '99.9', 1]] as [$p, $r, $n]) {
            $rows = $calc->annuity($p, $r, $n, CarbonImmutable::parse('2026-01-10'));
            $this->assertSame($p, $this->sum($rows, 'principal'), "P={$p} r={$r} n={$n}");
            foreach ($rows as $row) {
                $this->assertFalse(BigDecimal::of($row['principal'])->isNegative());
                $this->assertSame(2, BigDecimal::of($row['interest'])->getScale());
            }
        }
    }

    public function test_due_dates_clamp_to_month_end(): void
    {
        $rows = (new LoanScheduleCalculator)->annuity('3000.00', '0', 3, CarbonImmutable::parse('2026-01-31'));

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_map(fn ($r) => $r['due_date']->toDateString(), $rows));
    }
}
