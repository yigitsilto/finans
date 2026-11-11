<?php

namespace App\Services;

use App\Models\LoanInstallment;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Yaklaşan ZORUNLU ödemeler (likidite riski için).
 *
 * - Ödenmemiş kredi taksitleri: vadesi ufuk sonuna kadar olanlar (gecikmişler dahil, tamamı)
 * - Ödenmemiş tekrarlayan giderler: ufuk sonuna kadar vadesi gelen aylar; gecikmişler en fazla
 *   OVERDUE_LOOKBACK_MONTHS ay geriye bakılarak (çok eski tanımlar sonsuz borç üretmesin)
 *
 * Planlanan tutarlar kullanılır (taksit toplamı, giderin beklenen aylık tutarı).
 */
class ObligationService
{
    public const OVERDUE_LOOKBACK_MONTHS = 3;

    /**
     * @return Collection<int, array{type: string, label: string, due_date: CarbonImmutable, amount: string, overdue: bool, url: string}>
     */
    public function upcoming(CarbonImmutable $horizonEnd, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::today();

        $installments = LoanInstallment::with('loan')
            ->whereDate('due_date', '<=', $horizonEnd)
            ->whereDoesntHave('payments')
            ->orderBy('due_date')
            ->get()
            ->map(fn (LoanInstallment $i) => [
                'type' => 'loan',
                'label' => "{$i->loan->name} – {$i->number}. taksit",
                'due_date' => CarbonImmutable::parse($i->due_date),
                'amount' => (string) $i->total,
                'overdue' => $i->due_date->lt($today),
                'url' => route('loans.show', $i->loan_id),
            ]);

        $expenses = collect();
        $recurring = RecurringExpense::where('is_active', true)->get();
        if ($recurring->isNotEmpty()) {
            $paid = Transaction::whereNotNull('recurring_expense_id')
                ->whereNull('reversal_of_id')->whereDoesntHave('reversal')
                ->get(['recurring_expense_id', 'expense_period'])
                ->map(fn ($t) => $t->recurring_expense_id.'|'.$t->expense_period->toDateString())
                ->flip();

            $period = $today->startOfMonth()->subMonths(self::OVERDUE_LOOKBACK_MONTHS);
            while ($period->lte($horizonEnd)) {
                foreach ($recurring as $e) {
                    $due = $e->dueDateFor($period);
                    if (! $e->appliesTo($period) || $due->gt($horizonEnd) || $paid->has($e->id.'|'.$period->toDateString())) {
                        continue;
                    }
                    $expenses->push([
                        'type' => 'expense',
                        'label' => $e->name.' – '.$period->locale('tr')->translatedFormat('F Y'),
                        'due_date' => $due,
                        'amount' => (string) $e->amount,
                        'overdue' => $due->lt($today),
                        'url' => route('expenses.index', ['month' => $period->format('Y-m')]),
                    ]);
                }
                $period = $period->addMonth();
            }
        }

        return $installments->concat($expenses)->sortBy(fn ($o) => $o['due_date']->timestamp)->values();
    }

    /** @param Collection<int, array{amount: string}> $items */
    public static function total(Collection $items): string
    {
        return (string) Money::of($items->reduce(fn ($c, $o) => $c->plus($o['amount']), BigDecimal::zero()));
    }
}
