<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Nakit akış motoru (PRD §16–17).
 *
 *   A. Başlangıç      : hesap bakiyeleri (from − 1 gün sonu). Kullanılabilir = kasa + banka; blokeli POS ayrı.
 *   B. Girişler       : dönemdeki `inflow` hareketleri (kategori bazlı)
 *   C. Çıkışlar       : dönemdeki `outflow` hareketleri (kategori bazlı; kredi anapara/faiz ayrı satır)
 *   D. Net akış       : B − C
 *   E. Dönem sonu kullanılabilir nakit
 *                     = A(kullanılabilir) + D − POS blokesindeki artış + dönem içi açılış kayıtları (kasa/banka)
 *
 * Transferler nakit akışı değildir (hesaplar arası). POS'a giren tahsilat nakit girişidir ama valör
 * gününe kadar blokelidir; bu yüzden kullanılabilir nakde geçişi "POS blokesindeki değişim" satırıyla
 * açıkça gösterilir. "Açılış" türündeki kayıtlar nakit akışı değil, bakiye tanımıdır.
 *
 * Mutabakat: formülle bulunan kapanış, hesap defterinden bağımsız hesaplanan kapanışla karşılaştırılır.
 */
class CashflowService
{
    public const GRANULARITIES = ['day' => 'Günlük', 'week' => 'Haftalık', 'month' => 'Aylık'];

    public const MAX_PERIODS = 120;

    public function __construct(private readonly BalanceService $balances) {}

    /**
     * Tek dönem için tam nakit akış raporu.
     *
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $this->assertRange($from, $to);
        $accounts = Account::orderBy('type')->orderBy('name')->get();

        $openingBalances = $this->balances->balances($from->subDay());
        $closingBalances = $this->balances->balances($to);
        $opening = $this->positions($openingBalances, $accounts);
        $closingLedger = $this->positions($closingBalances, $accounts);

        $categories = $this->categoryTotals($from, $to);
        $inflow = $this->sum($categories->where('type', 'inflow'));
        $outflow = $this->sum($categories->where('type', 'outflow'));
        $net = $inflow->minus($outflow);

        $accountFlows = $this->accountFlows($from, $to);
        $posAccountIds = $accounts->where('type', AccountType::Pos)->pluck('id')->all();

        // Dönem içi açılış kayıtları (nakit akışı değil, bakiye tanımı)
        $openingsAvailable = BigDecimal::zero();
        $openingsPos = BigDecimal::zero();
        // POS blokesindeki değişim, akışlardan bağımsız hesaplanır: POS'a girişler − POS'tan aktarımlar
        $posChangeFromFlows = BigDecimal::zero();
        foreach ($accountFlows as $id => $f) {
            if (in_array($id, $posAccountIds, true)) {
                $openingsPos = $openingsPos->plus($f['openings']);
                $posChangeFromFlows = $posChangeFromFlows->plus($f['inflow'])->minus($f['outflow'])
                    ->plus($f['transfer_in'])->minus($f['transfer_out']);
            } else {
                $openingsAvailable = $openingsAvailable->plus($f['openings']);
            }
        }

        $closingAvailable = BigDecimal::of($opening['available'])->plus($net)->minus($posChangeFromFlows)->plus($openingsAvailable);
        $closingPos = BigDecimal::of($opening['pos'])->plus($posChangeFromFlows)->plus($openingsPos);

        $accountRows = $accounts->map(function (Account $a) use ($accountFlows, $openingBalances, $closingBalances) {
            $f = $accountFlows[$a->id] ?? $this->emptyFlow();
            $openingBalance = $openingBalances[$a->id] ?? '0.00';
            $closingBalance = $closingBalances[$a->id] ?? '0.00';
            $computed = BigDecimal::of($openingBalance)->plus($f['openings'])->plus($f['inflow'])->minus($f['outflow'])
                ->plus($f['transfer_in'])->minus($f['transfer_out']);

            return [
                'account' => $a,
                'opening' => $openingBalance,
                'openings' => (string) Money::of($f['openings']),
                'inflow' => (string) Money::of($f['inflow']),
                'outflow' => (string) Money::of($f['outflow']),
                'transfer_in' => (string) Money::of($f['transfer_in']),
                'transfer_out' => (string) Money::of($f['transfer_out']),
                'closing' => $closingBalance,
                'ok' => $computed->isEqualTo($closingBalance),
            ];
        });

        $checks = [
            [
                'label' => 'Dönem sonu kullanılabilir nakit (formül) = kasa + banka hesap bakiyeleri',
                'expected' => (string) Money::of($closingAvailable),
                'actual' => $closingLedger['available'],
            ],
            [
                'label' => 'Dönem sonu blokeli POS (formül) = POS hesap bakiyeleri',
                'expected' => (string) Money::of($closingPos),
                'actual' => $closingLedger['pos'],
            ],
            [
                'label' => 'Toplam kasa + toplam banka = toplam kullanılabilir nakit',
                'expected' => (string) Money::of(BigDecimal::of($closingLedger['cash'])->plus($closingLedger['bank'])),
                'actual' => $closingLedger['available'],
            ],
            [
                'label' => 'Kategori toplamları = hesap bazlı giriş/çıkış toplamları',
                'expected' => (string) Money::of($net),
                'actual' => (string) Money::of(collect($accountFlows)->reduce(fn ($c, $f) => $c->plus($f['inflow'])->minus($f['outflow']), BigDecimal::zero())),
            ],
        ];
        foreach ($checks as &$check) {
            $check['ok'] = BigDecimal::of($check['expected'])->isEqualTo($check['actual']);
        }
        unset($check);
        $checks[] = [
            'label' => 'Her hesapta açılış + hareketler = kapanış',
            'expected' => $accountRows->count().' hesap',
            'actual' => $accountRows->where('ok', true)->count().' hesap tutarlı',
            'ok' => $accountRows->every(fn ($r) => $r['ok']),
        ];

        return [
            'from' => $from,
            'to' => $to,
            'opening' => $opening,
            'inflows' => $this->grouped($categories->where('type', 'inflow')),
            'outflows' => $this->grouped($categories->where('type', 'outflow')),
            'total_inflow' => (string) Money::of($inflow),
            'total_outflow' => (string) Money::of($outflow),
            'net' => (string) Money::of($net),
            'operating_net' => (string) Money::of($this->netByActivity($categories, 'operating')),
            'financing_net' => (string) Money::of($this->netByActivity($categories, 'financing')),
            'loan_installments' => [
                'principal' => (string) Money::of($this->sum($categories->where('code', 'loan_principal'))),
                'interest' => (string) Money::of($this->sum($categories->where('code', 'loan_interest'))),
            ],
            'pos_change' => (string) Money::of($posChangeFromFlows),
            'period_openings_available' => (string) Money::of($openingsAvailable),
            'period_openings_pos' => (string) Money::of($openingsPos),
            'closing_available' => (string) Money::of($closingAvailable),
            'closing_pos' => (string) Money::of($closingPos),
            'closing_total' => (string) Money::of($closingAvailable->plus($closingPos)),
            'closing_ledger' => $closingLedger,
            'accounts' => $accountRows,
            'checks' => $checks,
            'reconciled' => collect($checks)->every(fn ($c) => $c['ok']),
        ];
    }

    /**
     * Günlük / haftalık / aylık kırılım. Her dönemin açılışı defterden BAĞIMSIZ hesaplanır
     * ve önceki dönemin formülle bulunan kapanışıyla karşılaştırılır (devir mutabakatı).
     *
     * @return array{rows: list<array<string, mixed>>, carry_over_ok: bool}
     */
    public function series(CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $rows = [];
        $previous = null;

        foreach ($this->periods($from, $to, $granularity) as [$start, $end]) {
            $r = $this->summary($start, $end);
            $r['carry_over_ok'] = $previous === null
                || (BigDecimal::of($previous['closing_available'])->isEqualTo($r['opening_available'])
                    && BigDecimal::of($previous['closing_pos'])->isEqualTo($r['opening_pos']));
            $rows[] = $r;
            $previous = $r;
        }

        return ['rows' => $rows, 'carry_over_ok' => collect($rows)->every(fn ($r) => $r['carry_over_ok'])];
    }

    /**
     * Rapor dönemleri. Hafta pazartesi başlar; ilk ve son dönem seçilen aralığa kırpılır.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function periods(CarbonImmutable $from, CarbonImmutable $to, string $granularity): array
    {
        $this->assertRange($from, $to);
        if (! array_key_exists($granularity, self::GRANULARITIES)) {
            throw new InvalidArgumentException('Geçersiz dönem türü.');
        }

        $periods = [];
        $cursor = $from;
        while ($cursor->lte($to)) {
            $end = match ($granularity) {
                'day' => $cursor,
                'week' => $cursor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
                'month' => $cursor->endOfMonth()->startOfDay(),
            };
            $end = $end->gt($to) ? $to : $end;
            $periods[] = [$cursor, $end];
            $cursor = $end->addDay();

            if (count($periods) > self::MAX_PERIODS) {
                throw new InvalidArgumentException('Seçilen aralık çok fazla dönem içeriyor (en fazla '.self::MAX_PERIODS.'). Daha geniş bir dönem türü seçin.');
            }
        }

        return $periods;
    }

    /** Kırılım satırı için hafif özet (kategori detayı yok). */
    private function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $accounts = Account::all(['id', 'type']);
        $opening = $this->positions($this->balances->balances($from->subDay()), $accounts);

        $row = DB::selectOne(<<<'SQL'
            SELECT
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'inflow'), 0)::numeric(18,2)::text AS inflow,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'outflow'), 0)::numeric(18,2)::text AS outflow,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'opening' AND ta.type <> 'pos'), 0)::numeric(18,2)::text AS openings_available,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'opening' AND ta.type = 'pos'), 0)::numeric(18,2)::text AS openings_pos,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'inflow' AND ta.type = 'pos'), 0)
                  - COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'transfer' AND fa.type = 'pos'), 0) AS pos_change
            FROM transactions t
            LEFT JOIN accounts ta ON ta.id = t.to_account_id
            LEFT JOIN accounts fa ON fa.id = t.from_account_id
            WHERE t.date BETWEEN ? AND ?
        SQL, [$from->toDateString(), $to->toDateString()]);

        $net = BigDecimal::of($row->inflow)->minus($row->outflow);
        $posChange = BigDecimal::of($row->pos_change)->toScale(2);

        return [
            'from' => $from,
            'to' => $to,
            'opening_available' => $opening['available'],
            'opening_pos' => $opening['pos'],
            'inflow' => $row->inflow,
            'outflow' => $row->outflow,
            'net' => (string) Money::of($net),
            'pos_change' => (string) $posChange,
            'period_openings' => (string) Money::of(BigDecimal::of($row->openings_available)->plus($row->openings_pos)),
            'closing_available' => (string) Money::of(BigDecimal::of($opening['available'])->plus($net)->minus($posChange)->plus($row->openings_available)),
            'closing_pos' => (string) Money::of(BigDecimal::of($opening['pos'])->plus($posChange)->plus($row->openings_pos)),
        ];
    }

    /**
     * @param  array<int, string>  $balances
     * @return array{cash: string, bank: string, available: string, pos: string, total: string}
     */
    private function positions(array $balances, Collection $accounts): array
    {
        $t = ['cash' => BigDecimal::zero(), 'bank' => BigDecimal::zero(), 'pos' => BigDecimal::zero()];
        foreach ($accounts as $a) {
            $t[$a->type->value] = $t[$a->type->value]->plus($balances[$a->id] ?? '0');
        }
        $available = $t['cash']->plus($t['bank']);

        return [
            'cash' => (string) Money::of($t['cash']),
            'bank' => (string) Money::of($t['bank']),
            'available' => (string) Money::of($available),
            'pos' => (string) Money::of($t['pos']),
            'total' => (string) Money::of($available->plus($t['pos'])),
        ];
    }

    /** Kategori bazlı giriş/çıkış toplamları (ters kayıtlar negatif tutarla düşer). */
    private function categoryTotals(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return collect(DB::select(<<<'SQL'
            SELECT t.type, c.id, c.code, c.name, c.activity, c.cost_type, c.sort,
                   COALESCE(p.name, c.name) AS group_name, COALESCE(p.sort, c.sort) AS group_sort,
                   SUM(t.amount)::numeric(18,2)::text AS total
            FROM transactions t
            JOIN categories c ON c.id = t.category_id
            LEFT JOIN categories p ON p.id = c.parent_id
            WHERE t.type IN ('inflow', 'outflow') AND t.date BETWEEN ? AND ?
            GROUP BY t.type, c.id, c.code, c.name, c.activity, c.cost_type, c.sort, p.name, p.sort
            HAVING SUM(t.amount) <> 0
            ORDER BY group_sort, c.sort
        SQL, [$from->toDateString(), $to->toDateString()]));
    }

    /** @return array<int, array{openings: BigDecimal, inflow: BigDecimal, outflow: BigDecimal, transfer_in: BigDecimal, transfer_out: BigDecimal}> */
    private function accountFlows(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT a.id,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'opening' AND t.to_account_id = a.id), 0)::text AS openings,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'inflow' AND t.to_account_id = a.id), 0)::text AS inflow,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'outflow' AND t.from_account_id = a.id), 0)::text AS outflow,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'transfer' AND t.to_account_id = a.id), 0)::text AS transfer_in,
                COALESCE(SUM(t.amount) FILTER (WHERE t.type = 'transfer' AND t.from_account_id = a.id), 0)::text AS transfer_out
            FROM accounts a
            LEFT JOIN transactions t ON (t.to_account_id = a.id OR t.from_account_id = a.id) AND t.date BETWEEN ? AND ?
            GROUP BY a.id
        SQL, [$from->toDateString(), $to->toDateString()]);

        $flows = [];
        foreach ($rows as $r) {
            $flows[(int) $r->id] = [
                'openings' => BigDecimal::of($r->openings),
                'inflow' => BigDecimal::of($r->inflow),
                'outflow' => BigDecimal::of($r->outflow),
                'transfer_in' => BigDecimal::of($r->transfer_in),
                'transfer_out' => BigDecimal::of($r->transfer_out),
            ];
        }

        return $flows;
    }

    private function emptyFlow(): array
    {
        return array_fill_keys(['openings', 'inflow', 'outflow', 'transfer_in', 'transfer_out'], BigDecimal::zero());
    }

    /** Faaliyet → grup → kategori ağacı, ara toplamlarla. */
    private function grouped(Collection $rows): array
    {
        $result = [];
        foreach (['operating' => 'İşletme faaliyetleri', 'financing' => 'Finansman faaliyetleri'] as $activity => $label) {
            $activityRows = $rows->where('activity', $activity);
            if ($activityRows->isEmpty()) {
                continue;
            }
            $groups = $activityRows->groupBy('group_name')->map(fn ($items, $name) => [
                'name' => $name,
                'total' => (string) Money::of($this->sum($items)),
                'items' => $items->count() > 1 || $items->first()->name !== $name
                    ? $items->map(fn ($i) => ['code' => $i->code, 'name' => $i->name, 'total' => $i->total])->values()->all()
                    : [],
            ])->values()->all();

            $result[] = ['activity' => $activity, 'label' => $label, 'total' => (string) Money::of($this->sum($activityRows)), 'groups' => $groups];
        }

        return $result;
    }

    private function netByActivity(Collection $rows, string $activity): BigDecimal
    {
        return $this->sum($rows->where('type', 'inflow')->where('activity', $activity))
            ->minus($this->sum($rows->where('type', 'outflow')->where('activity', $activity)));
    }

    private function sum(Collection $rows): BigDecimal
    {
        return $rows->reduce(fn ($c, $r) => $c->plus($r->total), BigDecimal::zero());
    }

    private function assertRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('Bitiş tarihi başlangıçtan önce olamaz.');
        }
    }
}
