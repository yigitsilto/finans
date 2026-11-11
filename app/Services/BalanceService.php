<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hesap bakiyeleri her zaman hareketlerden hesaplanır (saklanmaz).
 * Ters kayıtlar negatif tutarlı olduğundan düz SUM doğru sonucu verir.
 */
class BalanceService
{
    /**
     * Tüm hesapların belirtilen tarih sonundaki bakiyesi.
     *
     * @return array<int, string> account_id => "1234.50"
     */
    public function balances(?CarbonInterface $asOf = null): array
    {
        $asOf ??= today();

        $rows = DB::select(<<<'SQL'
            SELECT a.id,
                   COALESCE(SUM(CASE WHEN t.to_account_id = a.id THEN t.amount ELSE -t.amount END), 0)::numeric(18,2)::text AS balance
            FROM accounts a
            LEFT JOIN transactions t
                   ON (t.to_account_id = a.id OR t.from_account_id = a.id) AND t.date <= ?
            GROUP BY a.id
        SQL, [$asOf->toDateString()]);

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->id] = $row->balance;
        }

        return $balances;
    }

    public function balance(Account $account, ?CarbonInterface $asOf = null): string
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(CASE WHEN to_account_id = ? THEN amount ELSE -amount END), 0)::numeric(18,2)::text AS balance
            FROM transactions
            WHERE (to_account_id = ? OR from_account_id = ?) AND date <= ?
        SQL, [$account->id, $account->id, $account->id, ($asOf ?? today())->toDateString()]);

        return $row->balance;
    }

    /**
     * Verilen tarihten itibaren hesabın gün sonu bakiyelerinin en düşüğü.
     * Geriye dönük bir çıkışın sonraki günlerde bakiyeyi eksiye düşürüp düşürmediğini yakalar.
     */
    public function minimumBalanceFrom(Account $account, CarbonInterface $from): string
    {
        $row = DB::selectOne(<<<'SQL'
            WITH daily AS (
                SELECT date, SUM(CASE WHEN to_account_id = ? THEN amount ELSE -amount END) AS net
                FROM transactions
                WHERE to_account_id = ? OR from_account_id = ?
                GROUP BY date
            ), running AS (
                SELECT date, SUM(net) OVER (ORDER BY date) AS balance FROM daily
            )
            SELECT COALESCE(MIN(balance), 0)::numeric(18,2)::text AS minimum FROM running WHERE date >= ?
        SQL, [$account->id, $account->id, $account->id, $from->toDateString()]);

        return $row->minimum;
    }

    /**
     * Likidite özeti. Blokeli POS kullanılabilir nakde dahil edilmez, ayrı gösterilir.
     *
     * @return array{cash: string, bank: string, available: string, pos_blocked: string, total_liquidity: string}
     */
    public function summary(?CarbonInterface $asOf = null): array
    {
        $balances = $this->balances($asOf);
        $totals = ['cash' => BigDecimal::zero(), 'bank' => BigDecimal::zero(), 'pos' => BigDecimal::zero()];

        foreach (Account::query()->get(['id', 'type']) as $account) {
            $totals[$account->type->value] = $totals[$account->type->value]->plus($balances[$account->id] ?? '0');
        }

        $available = $totals['cash']->plus($totals['bank']);

        return [
            'cash' => (string) Money::of($totals['cash']),
            'bank' => (string) Money::of($totals['bank']),
            'available' => (string) Money::of($available),
            'pos_blocked' => (string) Money::of($totals['pos']),
            'total_liquidity' => (string) Money::of($available->plus($totals['pos'])),
        ];
    }
}
