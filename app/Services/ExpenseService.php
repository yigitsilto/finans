<?php

namespace App\Services;

use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(private readonly TransactionService $transactions) {}

    /**
     * Seçilen ay için tekrarlayan giderlerin durumu (ödendi / bekliyor / gecikti).
     *
     * @return Collection<int, array{expense: RecurringExpense, due_date: CarbonImmutable, payment: ?Transaction, status: string}>
     */
    public function monthStatus(CarbonImmutable $period): Collection
    {
        $period = $period->startOfMonth();

        $payments = Transaction::query()
            ->where('expense_period', $period->toDateString())
            ->whereNull('reversal_of_id')->whereDoesntHave('reversal')
            ->get()->keyBy('recurring_expense_id');

        return RecurringExpense::with(['category.parent', 'account'])->orderBy('day_of_month')->orderBy('name')->get()
            ->filter(fn (RecurringExpense $e) => $e->appliesTo($period) || $payments->has($e->id))
            ->map(function (RecurringExpense $e) use ($period, $payments) {
                $due = $e->dueDateFor($period);
                $payment = $payments->get($e->id);

                return [
                    'expense' => $e,
                    'due_date' => $due,
                    'payment' => $payment,
                    'status' => $payment ? 'paid' : ($due->lt(CarbonImmutable::today()) ? 'overdue' : 'pending'),
                ];
            })->values();
    }

    /**
     * Tekrarlayan gideri belirtilen ay için öder (tek nakit çıkışı). Aynı ay iki kez ödenemez.
     */
    public function pay(RecurringExpense $expense, CarbonImmutable $period, array $data, User $user): Transaction
    {
        $period = $period->startOfMonth();

        return DB::transaction(function () use ($expense, $period, $data, $user) {
            $expense = RecurringExpense::whereKey($expense->id)->lockForUpdate()->firstOrFail();

            if (! $expense->appliesTo($period)) {
                throw ValidationException::withMessages(['period' => 'Bu gider seçilen ay için geçerli değil.']);
            }

            $alreadyPaid = Transaction::where('recurring_expense_id', $expense->id)
                ->where('expense_period', $period->toDateString())
                ->whereNull('reversal_of_id')->whereDoesntHave('reversal')->exists();
            if ($alreadyPaid) {
                throw ValidationException::withMessages(['period' => "{$expense->name} bu ay için zaten ödenmiş."]);
            }

            return $this->transactions->create([
                'type' => 'outflow',
                'date' => $data['date'],
                'amount' => $data['amount'] ?? $expense->amount,
                'from_account_id' => $data['account_id'] ?? $expense->account_id,
                'category_id' => $expense->category_id,
                'counterparty' => $expense->counterparty,
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $expense->name.' – '.$period->locale('tr')->translatedFormat('F Y'),
            ], $user, ['recurring_expense_id' => $expense->id, 'expense_period' => $period->toDateString()]);
        });
    }

    /**
     * Gider analizi: sabit / değişken / diğer çıkışlar ve oranları.
     * Oranların paydası seçilen dönemdeki TOPLAM nakit çıkışıdır (PRD §19).
     *
     * @return array{total_outflow: string, fixed: string, variable: string, other: string,
     *     fixed_ratio: ?string, variable_ratio: ?string, by_category: Collection}
     */
    public function analysis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = collect(DB::select(<<<'SQL'
            SELECT COALESCE(p.id, c.id) AS group_id, COALESCE(p.name, c.name) AS group_name, c.id AS category_id,
                   c.name AS category_name, c.cost_type, c.activity, SUM(t.amount)::numeric(18,2)::text AS total
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            LEFT JOIN categories p ON p.id = c.parent_id
            WHERE t.type = 'outflow' AND t.date BETWEEN ? AND ?
            GROUP BY p.id, p.name, c.id, c.name, c.cost_type, c.activity
            HAVING SUM(t.amount) <> 0
            ORDER BY SUM(t.amount) DESC
        SQL, [$from->toDateString(), $to->toDateString()]));

        $sum = fn (?string $costType) => $rows
            ->filter(fn ($r) => $costType === 'other' ? $r->cost_type === null : $r->cost_type === $costType)
            ->reduce(fn ($c, $r) => $c->plus($r->total), BigDecimal::zero());

        $total = $rows->reduce(fn ($c, $r) => $c->plus($r->total), BigDecimal::zero());
        $fixed = $sum('fixed');
        $variable = $sum('variable');
        $ratio = fn (BigDecimal $part) => $total->isPositive()
            ? (string) $part->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HALF_UP)
            : null;

        return [
            'total_outflow' => (string) Money::of($total),
            'fixed' => (string) Money::of($fixed),
            'variable' => (string) Money::of($variable),
            'other' => (string) Money::of($sum('other')),
            'fixed_ratio' => $ratio($fixed),
            'variable_ratio' => $ratio($variable),
            'by_category' => $rows,
        ];
    }
}
