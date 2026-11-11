<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\CashflowService;
use App\Services\LoanService;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Beklenen değerler test dışında (Python Decimal) bağımsız olarak hesaplanmıştır:
 *   kredi taksiti A = 4.080,27 (anapara 3.960,27 + faiz 120,00)
 *   Mart: giriş 50.000,00 · çıkış 32.330,77 · net 17.669,23
 *   POS blokesi değişimi −2.000 · dönem içi açılış 7.000
 *   kapanış kullanılabilir = 65.000 + 17.669,23 + 2.000 + 7.000 = 91.669,23
 *   (kasa 12.749,50 + banka 71.919,73 + banka2 7.000)
 */
class CashflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashflowService $cashflow;

    private TransactionService $tx;

    private User $user;

    private Account $cash;

    private Account $bank;

    private Account $bank2;

    private Account $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cashflow = app(CashflowService::class);
        $this->tx = app(TransactionService::class);
        $this->user = User::factory()->finance()->create();
        $this->cash = Account::factory()->cash()->create(['name' => 'Kasa']);
        $this->bank = Account::factory()->bank()->create(['name' => 'Banka']);
        $this->bank2 = Account::factory()->bank()->create(['name' => 'Banka 2']);
        $this->pos = Account::factory()->pos($this->bank)->create(['name' => 'POS']);
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function in(Account $to, string $amount, string $date, string $code = 'commission_income', array $extra = []): Transaction
    {
        return $this->tx->create(array_merge(['type' => 'inflow', 'date' => $date, 'amount' => $amount, 'to_account_id' => $to->id, 'category_id' => $this->cat($code)], $extra), $this->user);
    }

    private function out(Account $from, string $amount, string $date, string $code): Transaction
    {
        return $this->tx->create(['type' => 'outflow', 'date' => $date, 'amount' => $amount, 'from_account_id' => $from->id, 'category_id' => $this->cat($code)], $this->user);
    }

    private function opening(Account $to, string $amount, string $date): void
    {
        $this->tx->create(['type' => 'opening', 'date' => $date, 'amount' => $amount, 'to_account_id' => $to->id], $this->user);
    }

    /** Şubat devri + kapsamlı bir Mart + Nisan'da dönem dışı bir hareket. */
    private function scenario(): void
    {
        // Şubat
        $this->opening($this->cash, '10.000', '2026-02-01');
        $this->opening($this->bank, '50.000', '2026-02-01');
        $this->in($this->bank, '5.000', '2026-02-15');
        $posFeb = $this->in($this->pos, '2.000', '2026-02-25', 'customer_collection', ['pos_settlement_date' => '2026-03-02']);

        // Mart
        $this->tx->settlePos($posFeb, '2026-03-02', $this->user);
        $loan = app(LoanService::class)->create([
            'name' => 'Kredi', 'bank_name' => 'Banka', 'account_id' => $this->bank->id, 'start_date' => '2026-03-03',
            'principal' => '12.000', 'annual_interest_rate' => '12', 'term_months' => 3, 'first_due_date' => '2026-03-20',
            'record_disbursement' => true,
        ], $this->user);
        $this->in($this->bank, '20.000', '2026-03-05');
        $this->out($this->bank, '27.000', '2026-03-06', 'fx_rent');
        $this->out($this->cash, '1.250,50', '2026-03-07', 'vx_electricity');
        $this->tx->create(['type' => 'transfer', 'date' => '2026-03-08', 'amount' => '4.000', 'from_account_id' => $this->bank->id, 'to_account_id' => $this->cash->id], $this->user);
        $partner = new Partner(['name' => 'Ortak']);
        $partner->created_by = $this->user->id;
        $partner->save();
        $this->in($this->bank, '15.000', '2026-03-09', 'partner_capital', ['partner_id' => $partner->id]);
        $posMar = $this->in($this->pos, '3.000', '2026-03-10', 'customer_collection', ['pos_settlement_date' => '2026-03-12']);
        $this->opening($this->bank2, '7.000', '2026-03-10');
        $this->tx->settlePos($posMar, '2026-03-12', $this->user);
        $wrong = $this->in($this->bank, '1.000', '2026-03-15');
        $this->tx->reverse($wrong, '2026-03-16', 'Mükerrer', $this->user);
        app(LoanService::class)->payInstallment($loan->installments()->first(), '2026-03-20', $this->bank->id, $this->user);

        // Nisan (Mart raporuna girmemeli)
        $this->in($this->bank, '999', '2026-04-01');
    }

    // --- Formüller ---

    public function test_march_report_matches_independently_computed_figures(): void
    {
        $this->scenario();

        $r = $this->cashflow->report($this->d('2026-03-01'), $this->d('2026-03-31'));

        $this->assertSame(['cash' => '10000.00', 'bank' => '55000.00', 'available' => '65000.00', 'pos' => '2000.00', 'total' => '67000.00'], $r['opening']);
        $this->assertSame('50000.00', $r['total_inflow']);
        $this->assertSame('32330.77', $r['total_outflow']);
        $this->assertSame('17669.23', $r['net']);
        $this->assertSame('-5250.50', $r['operating_net']);
        $this->assertSame('22919.73', $r['financing_net']);
        $this->assertSame(['principal' => '3960.27', 'interest' => '120.00'], $r['loan_installments']);
        $this->assertSame('-2000.00', $r['pos_change']);
        $this->assertSame('7000.00', $r['period_openings_available']);
        $this->assertSame('91669.23', $r['closing_available']);
        $this->assertSame('0.00', $r['closing_pos']);
        $this->assertSame(['cash' => '12749.50', 'bank' => '78919.73', 'available' => '91669.23', 'pos' => '0.00', 'total' => '91669.23'], $r['closing_ledger']);
        $this->assertTrue($r['reconciled'], json_encode(collect($r['checks'])->where('ok', false)->values(), JSON_UNESCAPED_UNICODE));
    }

    public function test_net_flow_formula_and_closing_formula_hold(): void
    {
        $this->scenario();
        $r = $this->cashflow->report($this->d('2026-03-01'), $this->d('2026-03-31'));

        // D = B − C
        $this->assertTrue(BigDecimal::of($r['total_inflow'])->minus($r['total_outflow'])->isEqualTo($r['net']));
        // E = A + D − ΔPOS + dönem içi açılışlar
        $this->assertTrue(BigDecimal::of($r['opening']['available'])->plus($r['net'])->minus($r['pos_change'])
            ->plus($r['period_openings_available'])->isEqualTo($r['closing_available']));
        // Faaliyet ayrımı neti bölüştürür
        $this->assertTrue(BigDecimal::of($r['operating_net'])->plus($r['financing_net'])->isEqualTo($r['net']));
    }

    public function test_transfers_and_pos_settlements_are_not_counted_as_cash_flow(): void
    {
        $this->opening($this->bank, '1.000', '2026-03-01');
        $this->tx->create(['type' => 'transfer', 'date' => '2026-03-02', 'amount' => '400', 'from_account_id' => $this->bank->id, 'to_account_id' => $this->cash->id], $this->user);

        $r = $this->cashflow->report($this->d('2026-03-02'), $this->d('2026-03-02'));

        $this->assertSame('0.00', $r['total_inflow']);
        $this->assertSame('0.00', $r['total_outflow']);
        $this->assertSame($r['opening']['available'], $r['closing_available']);
        $this->assertTrue($r['reconciled']);
    }

    public function test_reversal_in_later_period_does_not_rewrite_earlier_period(): void
    {
        $this->opening($this->bank, '100', '2026-02-01');
        $t = $this->in($this->bank, '1.000', '2026-02-10');
        $this->tx->reverse($t, '2026-03-05', 'Karşılıksız', $this->user);

        $feb = $this->cashflow->report($this->d('2026-02-01'), $this->d('2026-02-28'));
        $mar = $this->cashflow->report($this->d('2026-03-01'), $this->d('2026-03-31'));

        $this->assertSame('1000.00', $feb['total_inflow']);
        $this->assertSame('-1000.00', $mar['total_inflow']);
        $this->assertSame($feb['closing_available'], $mar['opening']['available']);
        $this->assertTrue($feb['reconciled'] && $mar['reconciled']);
    }

    public function test_empty_period(): void
    {
        $r = $this->cashflow->report($this->d('2026-03-01'), $this->d('2026-03-31'));

        $this->assertSame('0.00', $r['net']);
        $this->assertSame('0.00', $r['closing_available']);
        $this->assertSame([], $r['inflows']);
        $this->assertTrue($r['reconciled']);
    }

    // --- Devir mutabakatı ---

    public function test_carry_over_chain_daily_weekly_monthly(): void
    {
        $this->scenario();

        foreach (['day' => 31, 'week' => 6, 'month' => 1] as $granularity => $count) {
            $s = $this->cashflow->series($this->d('2026-03-01'), $this->d('2026-03-31'), $granularity);

            $this->assertCount($count, $s['rows'], $granularity);
            $this->assertTrue($s['carry_over_ok'], "{$granularity}: önceki kapanış = sonraki açılış");
            $this->assertSame('65000.00', $s['rows'][0]['opening_available']);
            $this->assertSame('91669.23', end($s['rows'])['closing_available']);
            $netSum = collect($s['rows'])->reduce(fn ($c, $r) => $c->plus($r['net']), BigDecimal::zero());
            $this->assertSame('17669.23', (string) $netSum, "{$granularity}: dönem netleri toplamı = ay neti");
        }

        $quarter = $this->cashflow->series($this->d('2026-02-01'), $this->d('2026-04-30'), 'month');
        $this->assertTrue($quarter['carry_over_ok']);
        $this->assertSame('65000.00', $quarter['rows'][0]['closing_available'], 'Şubat kapanışı = Mart açılışı');
        $this->assertSame('91669.23', $quarter['rows'][1]['closing_available']);
        $this->assertSame('92668.23', $quarter['rows'][2]['closing_available']);
    }

    public function test_series_detects_a_broken_carry_over(): void
    {
        $this->scenario();
        // Defter bakiyesini bir kuruş bozan sahte BalanceService
        $this->app->instance(BalanceService::class, new class extends BalanceService
        {
            public function balances(?CarbonInterface $asOf = null): array
            {
                $balances = parent::balances($asOf);
                if ($asOf && $asOf->toDateString() === '2026-03-15') {
                    $balances[array_key_first($balances)] = (string) BigDecimal::of($balances[array_key_first($balances)])->plus('0.01');
                }

                return $balances;
            }
        });
        $cashflow = app(CashflowService::class);

        $this->assertFalse($cashflow->series($this->d('2026-03-01'), $this->d('2026-03-31'), 'day')['carry_over_ok']);
        $this->assertFalse($cashflow->report($this->d('2026-03-01'), $this->d('2026-03-15'))['reconciled']);
    }

    public function test_periods_are_split_on_calendar_boundaries(): void
    {
        // 2026-03-04 Çarşamba
        $weeks = $this->cashflow->periods($this->d('2026-03-04'), $this->d('2026-03-20'), 'week');
        $this->assertSame([['2026-03-04', '2026-03-08'], ['2026-03-09', '2026-03-15'], ['2026-03-16', '2026-03-20']],
            array_map(fn ($p) => [$p[0]->toDateString(), $p[1]->toDateString()], $weeks));

        $months = $this->cashflow->periods($this->d('2026-01-15'), $this->d('2026-03-10'), 'month');
        $this->assertSame([['2026-01-15', '2026-01-31'], ['2026-02-01', '2026-02-28'], ['2026-03-01', '2026-03-10']],
            array_map(fn ($p) => [$p[0]->toDateString(), $p[1]->toDateString()], $months));

        $this->expectException(InvalidArgumentException::class);
        $this->cashflow->periods($this->d('2025-01-01'), $this->d('2026-03-01'), 'day');
    }

    // --- Ekran ---

    public function test_cashflow_screen(): void
    {
        $this->scenario();
        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/reports/cashflow?mode=month&date=2026-03-10')
            ->assertOk()
            ->assertSee('01.03.2026 – 31.03.2026')
            ->assertSee('65.000,00 ₺')->assertSee('50.000,00 ₺')->assertSee('32.330,77 ₺')
            ->assertSee('17.669,23 ₺')->assertSee('91.669,23 ₺')
            ->assertSee('Kredi anapara ödemesi')->assertSee('Kredi faiz ödemesi')
            ->assertSee('Finansman faaliyetleri')
            ->assertDontSee('Mutabakat hatası')
            ->assertDontSee('Eşleşmiyor');

        $this->get('/reports/cashflow?mode=custom&from=2026-02-01&to=2026-04-30&breakdown=month')->assertOk()->assertSee('Aylık kırılım');
        $this->get('/reports/cashflow?mode=custom&from=2025-01-01&to=2026-04-30&breakdown=day')->assertSessionHasErrors('breakdown');
        $this->get('/reports/cashflow?mode=custom&from=2026-04-01&to=2026-03-01')->assertSessionHasErrors('to');
    }

    public function test_negative_net_flow_warning(): void
    {
        $this->opening($this->bank, '10.000', '2026-03-01');
        $this->out($this->bank, '2.500', '2026-03-05', 'fx_rent');

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/reports/cashflow?mode=month&date=2026-03-01')
            ->assertSee('nakit çıkışı nakit girişinden fazla');
    }
}
