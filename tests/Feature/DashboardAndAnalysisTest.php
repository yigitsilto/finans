<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\DashboardService;
use App\Services\ExpenseService;
use App\Services\FinancialAnalysisService;
use App\Services\LoanService;
use App\Services\ObligationService;
use App\Services\ReceivableService;
use App\Services\TransactionService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DashboardAndAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $cash;

    private Account $bank;

    private Account $pos;

    private TransactionService $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00'));
        $this->user = User::factory()->finance()->create();
        $this->cash = Account::factory()->cash()->create(['name' => 'Kasa']);
        $this->bank = Account::factory()->bank()->create(['name' => 'Banka']);
        $this->pos = Account::factory()->pos($this->bank)->create(['name' => 'POS']);
        $this->tx = app(TransactionService::class);
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function opening(Account $a, string $amount, string $date = '2026-10-01'): void
    {
        $this->tx->create(['type' => 'opening', 'date' => $date, 'amount' => $amount, 'to_account_id' => $a->id], $this->user);
    }

    private function in(Account $a, string $amount, string $date, string $code = 'commission_income', array $extra = []): void
    {
        $this->tx->create(array_merge(['type' => 'inflow', 'date' => $date, 'amount' => $amount, 'to_account_id' => $a->id, 'category_id' => $this->cat($code)], $extra), $this->user);
    }

    private function out(Account $a, string $amount, string $date, string $code): void
    {
        $this->tx->create(['type' => 'outflow', 'date' => $date, 'amount' => $amount, 'from_account_id' => $a->id, 'category_id' => $this->cat($code)], $this->user);
    }

    private function receivable(string $amount, string $issue, string $due): void
    {
        $c = Customer::first() ?? tap(new Customer(['name' => 'Müşteri']), function ($c) {
            $c->created_by = $this->user->id;
            $c->save();
        });
        app(ReceivableService::class)->create([
            'customer_id' => $c->id, 'reference_no' => 'P-'.uniqid(), 'amount' => $amount, 'issue_date' => $issue, 'due_date' => $due,
        ], $this->user);
    }

    /** Not: model doğrudan oluşturulduğu için tutar Türkçe biçimle değil, Money::parse ile verilir. */
    private function expense(string $amount, int $day, string $start = '2026-11-01'): RecurringExpense
    {
        $e = new RecurringExpense(['name' => 'Kira', 'category_id' => $this->cat('fx_rent'), 'amount' => Money::parse($amount), 'day_of_month' => $day, 'start_date' => $start]);
        $e->created_by = $this->user->id;
        $e->save();

        return $e;
    }

    private function alerts(): Collection
    {
        return app(FinancialAnalysisService::class)->analyze()['alerts'];
    }

    // --- Kartlar ---

    public function test_cards_come_from_real_data(): void
    {
        $this->opening($this->cash, '2.000');
        $this->opening($this->bank, '50.000');
        $this->in($this->bank, '10.000', '2026-11-03');
        $this->in($this->pos, '1.500', '2026-11-05', 'customer_collection', ['pos_settlement_date' => '2026-11-20']);
        $this->out($this->bank, '27.000', '2026-11-06', 'fx_rent');
        $this->in($this->bank, '999', '2026-10-15'); // geçen ay: bu ayın kartlarına girmez
        $this->receivable('4.000', '2026-07-01', '2026-07-15');  // 119 gün → 90+
        $this->receivable('1.000', '2026-10-01', '2026-10-20');  // 22 gün
        $this->receivable('500', '2026-11-01', '2026-12-01');    // vadesi gelmemiş

        $c = app(DashboardService::class)->data()['cards'];

        $this->assertSame([
            'available' => '35999.00', 'bank' => '33999.00', 'cash' => '2000.00', 'pos_blocked' => '1500.00',
            'month_inflow' => '11500.00', 'month_outflow' => '27000.00', 'month_net' => '-15500.00',
            'receivables_total' => '5500.00', 'receivables_overdue' => '5000.00', 'receivables_critical' => '4000.00',
        ], $c);
    }

    public function test_empty_system_shows_zeros_not_fake_numbers(): void
    {
        Account::query()->update(['is_active' => false]);
        $data = app(DashboardService::class)->data();

        foreach ($data['cards'] as $value) {
            $this->assertSame('0.00', $value);
        }
        $this->assertSame([], $data['charts']['expenses']);
        $this->assertTrue($data['reconciled']);
    }

    // --- Grafikler ---

    public function test_chart_series_reconcile_with_cards(): void
    {
        $this->opening($this->bank, '50.000');
        $this->in($this->bank, '10.000', '2026-11-03');
        $this->out($this->bank, '27.000', '2026-11-06', 'fx_rent');
        $this->out($this->bank, '1.200', '2026-11-07', 'vx_electricity');
        $this->in($this->bank, '3.000', '2026-10-10');

        $data = app(DashboardService::class)->data();
        $flows = $data['charts']['monthly_flows'];

        $this->assertCount(12, $flows);
        $this->assertSame(['label' => 'Kas 2026', 'inflow' => '10000.00', 'outflow' => '28200.00'], end($flows));
        $this->assertSame('3000.00', $flows[10]['inflow']);
        $this->assertSame($data['cards']['available'], end($data['charts']['balance_trend'])['available']);
        $this->assertSame('53000.00', $data['charts']['balance_trend'][10]['available'], 'Ekim sonu bakiyesi');
        $this->assertCount(5, $data['charts']['aging']);

        $expenseSum = collect($data['charts']['expenses'])->reduce(fn ($c, $e) => $c->plus($e['amount']), BigDecimal::zero());
        $this->assertSame($data['cards']['month_outflow'], (string) $expenseSum, 'Gider dağılımı toplamı = bu ayki çıkış');
    }

    // --- Uyarılar (PRD §19) ---

    public function test_negative_cash_flow_alert(): void
    {
        $this->opening($this->bank, '50.000');
        $this->out($this->bank, '1.000', '2026-11-02', 'fx_rent');

        $alert = $this->alerts()->firstWhere('title', 'Negatif nakit akışı');
        $this->assertSame('warning', $alert['level']);
    }

    public function test_critical_receivable_alert(): void
    {
        $this->receivable('4.000', '2026-07-01', '2026-08-01'); // 102 gün

        $alert = $this->alerts()->firstWhere('title', 'Kritik tahsilat riski bulunmaktadır.');
        $this->assertSame('critical', $alert['level']);
        $this->assertStringContainsString('4.000,00 ₺', $alert['message']);
    }

    public function test_no_critical_alert_at_90_days_but_at_91(): void
    {
        $this->receivable('100', '2026-08-01', '2026-08-13'); // 11.11 − 13.08 = 90 gün
        $this->assertNull($this->alerts()->firstWhere('title', 'Kritik tahsilat riski bulunmaktadır.'));

        $this->receivable('100', '2026-08-01', '2026-08-12'); // 91 gün
        $this->assertNotNull($this->alerts()->firstWhere('title', 'Kritik tahsilat riski bulunmaktadır.'));
    }

    public function test_liquidity_risk_when_obligations_exceed_available_cash(): void
    {
        $this->opening($this->bank, '5.000');
        $this->expense('27.000', 25); // 25 Kasım kira, ödenmedi

        $a = app(FinancialAnalysisService::class)->analyze();

        $this->assertSame('27000.00', $a['obligation_total']);
        $this->assertSame('22000.00', $a['liquidity_gap']);
        $alert = $a['alerts']->firstWhere('title', 'Likidite riski tespit edildi.');
        $this->assertSame('critical', $alert['level']);
        $this->assertSame('critical', $a['alerts']->first()['level'], 'Kritik uyarılar en üstte');
    }

    public function test_liquidity_warning_and_ok_thresholds(): void
    {
        $this->opening($this->bank, '30.000');
        $this->expense('27.000', 25); // %90 → dikkat

        $this->assertSame('warning', $this->alerts()->firstWhere('title', 'Likidite sıkışıyor')['level']);
        $this->assertNull($this->alerts()->firstWhere('title', 'Likidite riski tespit edildi.'));

        $this->in($this->bank, '20.000', '2026-11-02'); // %54 → uyarı yok
        $this->assertNull($this->alerts()->firstWhere('title', 'Likidite sıkışıyor'));
    }

    public function test_blocked_pos_does_not_count_as_liquidity(): void
    {
        $this->opening($this->bank, '1.000');
        $this->in($this->pos, '50.000', '2026-11-05', 'customer_collection', ['pos_settlement_date' => '2026-11-30']);
        $this->expense('5.000', 20);

        $this->assertNotNull($this->alerts()->firstWhere('title', 'Likidite riski tespit edildi.'), 'Blokeli POS kullanılabilir nakit sayılmaz');
    }

    public function test_overdue_loan_installment_is_critical_and_counted(): void
    {
        $this->opening($this->bank, '100.000', '2026-08-01');
        $loan = app(LoanService::class)->create([
            'name' => 'Kredi', 'bank_name' => 'B', 'account_id' => $this->bank->id, 'start_date' => '2026-09-01',
            'principal' => '30.000', 'annual_interest_rate' => '0', 'term_months' => 3, 'first_due_date' => '2026-10-01',
        ], $this->user);

        $obligations = app(ObligationService::class)->upcoming(CarbonImmutable::parse('2026-12-11'));
        $this->assertCount(3, $obligations->where('type', 'loan'), 'Ekim (gecikmiş), Kasım (gecikmiş), Aralık');
        $this->assertSame('critical', $this->alerts()->firstWhere('title', 'Gecikmiş kredi taksiti')['level']);

        app(LoanService::class)->payInstallment($loan->installments()->first(), '2026-11-11', $this->bank->id, $this->user);
        $this->assertCount(2, app(ObligationService::class)->upcoming(CarbonImmutable::parse('2026-12-11'))->where('type', 'loan'));
    }

    public function test_recurring_expense_obligations_respect_payments_and_lookback(): void
    {
        $this->opening($this->bank, '100.000', '2026-01-01');
        $e = $this->expense('1.000', 5, '2026-01-01');
        app(ExpenseService::class)->pay($e, CarbonImmutable::parse('2026-10-01'), ['date' => '2026-10-05', 'account_id' => $this->bank->id], $this->user);

        $items = app(ObligationService::class)->upcoming(CarbonImmutable::parse('2026-12-11'))->where('type', 'expense');

        // Bakılan aylar: Ağu–Ara (3 ay geriye). Ekim ödendi → Ağu, Eyl, Kas, Ara.
        $this->assertSame(['2026-08-05', '2026-09-05', '2026-11-05', '2026-12-05'], $items->map(fn ($o) => $o['due_date']->toDateString())->values()->all());
        $this->assertSame(3, $items->where('overdue', true)->count());
    }

    public function test_reconciliation_failure_is_shown_on_dashboard(): void
    {
        $this->opening($this->bank, '1.000');
        $this->app->instance(BalanceService::class, new class extends BalanceService
        {
            public function balances(?CarbonInterface $asOf = null): array
            {
                $b = parent::balances($asOf);
                if ($asOf && $asOf->toDateString() === '2026-11-11') {
                    $k = array_key_first($b);
                    $b[$k] = (string) BigDecimal::of($b[$k])->plus('0.01');
                }

                return $b;
            }
        });

        $this->assertSame('critical', $this->alerts()->firstWhere('title', 'Mutabakat hatası')['level']);
        $this->actingAs($this->user)->get('/')->assertSee('Mutabakat hatası:');
    }

    // --- Ekran ve API ---

    public function test_dashboard_api_requires_login_and_returns_strings(): void
    {
        $this->opening($this->bank, '1.234,56');

        $this->getJson('/api/dashboard')->assertUnauthorized();

        $json = $this->actingAs(User::factory()->viewer()->create())->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['date', 'cards', 'reconciled', 'alerts', 'charts' => ['monthly_flows', 'balance_trend', 'aging', 'expenses']])
            ->json();

        $this->assertSame('1234.56', $json['cards']['available'], 'Tutarlar string döner, float değil');
    }

    public function test_dashboard_and_analysis_pages_render(): void
    {
        $this->opening($this->bank, '5.000');
        $this->expense('27.000', 25);
        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/')->assertOk()
            ->assertSee('Toplam kullanılabilir nakit')->assertSee('90+ gün kritik alacak')
            ->assertSee('5.000,00 ₺')->assertSee('Likidite riski tespit edildi.')
            ->assertSee('chart.umd.min.js');

        $this->get('/reports/analysis?horizon=7')->assertOk()->assertSee('7 günlük zorunlu ödemeler')->assertSee('Bu ufukta zorunlu ödeme yok.');
        $this->get('/reports/analysis?horizon=30')->assertOk()->assertSee('27.000,00 ₺')->assertSee('Likidite açığı');
        $this->get('/reports/analysis?horizon=45')->assertSessionHasErrors('horizon');
    }
}
