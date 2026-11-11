<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Eşit taksitli (annüite) kredi ödeme planı. Tamamen BigDecimal ile hesaplanır.
 *
 *   aylık oran  r = yıllık % / 100 / 12
 *   taksit      A = P · r · (1+r)^n / ((1+r)^n − 1)      (r = 0 ise A = P / n)
 *   faiz_i      = kalan_anapara · r   (kuruşa yuvarlanır)
 *   anapara_i   = A − faiz_i
 *
 * Yuvarlama farkı son taksitte kapatılır; böylece anapara toplamı daima kredi tutarına eşittir.
 */
class LoanScheduleCalculator
{
    private const SCALE = 20;

    /**
     * @return list<array{number: int, due_date: CarbonImmutable, principal: string, interest: string}>
     */
    public function annuity(string $principal, string $annualRatePercent, int $months, CarbonImmutable $firstDueDate): array
    {
        $p = BigDecimal::of($principal);
        $r = BigDecimal::of($annualRatePercent)->dividedBy('1200', self::SCALE, RoundingMode::HALF_UP);

        if ($r->isZero()) {
            $payment = $p->dividedBy($months, 2, RoundingMode::DOWN);
        } else {
            $growth = BigDecimal::one();
            for ($i = 0; $i < $months; $i++) {
                $growth = $growth->multipliedBy($r->plus(1))->toScale(self::SCALE, RoundingMode::HALF_UP);
            }
            $payment = $p->multipliedBy($r)->multipliedBy($growth)
                ->dividedBy($growth->minus(1), 2, RoundingMode::HALF_UP);
        }

        $rows = [];
        $remaining = $p;
        for ($n = 1; $n <= $months; $n++) {
            $interest = $remaining->multipliedBy($r)->toScale(2, RoundingMode::HALF_UP);
            $principalPart = $n === $months ? $remaining : $payment->minus($interest);

            if ($principalPart->isGreaterThan($remaining)) {
                $principalPart = $remaining;
            }

            $rows[] = [
                'number' => $n,
                'due_date' => $firstDueDate->addMonthsNoOverflow($n - 1),
                'principal' => (string) $principalPart->toScale(2),
                'interest' => (string) $interest,
            ];
            $remaining = $remaining->minus($principalPart);
        }

        return $rows;
    }
}
