<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\PartnerAdjustment;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\ExpenseService;
use App\Services\PartnerService;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PartnerAndExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $bank;

    private TransactionService $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->finance()->create();
        $this->bank = Account::factory()->bank()->create();
        $this->tx = app(TransactionService::class);
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function partner(string $name = 'Ali Zafir'): Partner
    {
        $p = new Partner(['name' => $name]);
        $p->created_by = $this->user->id;
        $p->save();

        return $p;
    }

    private function move(string $type, string $code, string $amount, ?Partner $partner = null, string $date = '2026-03-01'): Transaction
    {
        return $this->tx->create([
            'type' => $type, 'date' => $date, 'amount' => $amount, 'category_id' => $this->cat($code),
            ($type === 'inflow' ? 'to_account_id' : 'from_account_id') => $this->bank->id,
            'partner_id' => $partner?->id,
        ], $this->user);
    }

    private function expectError(callable $fn, string $field): void
    {
        try {
            $fn();
            $this->fail("ValidationException bekleniyordu ({$field})");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    // --- Ortaklar ---

    public function test_partner_categories_require_a_partner_and_others_reject_one(): void
    {
        $p = $this->partner();

        $this->expectError(fn () => $this->move('inflow', 'partner_capital', '1000'), 'partner_id');
        $this->expectError(fn () => $this->move('inflow', 'commission_income', '1000', $p), 'partner_id');
        $inactive = $this->partner('Pasif Ortak');
        $inactive->forceFill(['is_active' => false])->save();
        $this->expectError(fn () => $this->move('inflow', 'partner_capital', '1000', $inactive), 'partner_id');
    }

    public function test_partner_current_account_separates_capital_from_balance(): void
    {
        $p = $this->partner();
        $service = app(PartnerService::class);

        $this->move('inflow', 'partner_capital', '100.000', $p);
        $this->move('inflow', 'partner_loan_received', '20.000', $p);
        $this->move('outflow', 'partner_loan_repayment', '5.000', $p);
        $this->move('outflow', 'partner_payment', '3.000', $p);
        $service->addAdjustment($p, ['date' => '2026-03-02', 'direction' => 'credit', 'amount' => '1.000', 'description' => 'Ortak kırtasiyeyi cebinden ödedi'], $this->user);
        $service->addAdjustment($p, ['date' => '2026-03-03', 'direction' => 'debit', 'amount' => '500', 'description' => 'Mahsup'], $this->user);

        $this->assertSame([
            'capital' => '100000.00', 'loans_in' => '20000.00', 'loan_repayments' => '5000.00',
            'payments' => '3000.00', 'adjustments' => '500.00', 'balance' => '12500.00',
        ], $service->summary($p));

        // Nakitsiz düzeltmeler banka bakiyesini etkilemez.
        $this->assertSame('112000.00', app(BalanceService::class)->balance($this->bank));

        $ledger = $service->ledger($p);
        $this->assertSame('12500.00', $ledger->last()['balance']);
        $this->assertSame('0', $ledger->first()['effect'], 'Sermaye cari bakiyeyi etkilemez');
    }

    public function test_partner_payments_are_financing_not_operating_expenses(): void
    {
        $p = $this->partner();
        $this->move('inflow', 'partner_capital', '50.000', $p);
        $this->move('outflow', 'partner_payment', '10.000', $p);
        $this->move('outflow', 'fx_rent', '27.000');

        $a = app(ExpenseService::class)->analysis(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertSame('27000.00', $a['fixed']);
        $this->assertSame('10000.00', $a['other']);
        $partnerRow = $a['by_category']->firstWhere('category_name', 'Ortaklara yapılan ödemeler');
        $this->assertSame('financing', $partnerRow->activity);
        $this->assertNull($partnerRow->cost_type);
    }

    public function test_partner_adjustment_reversal(): void
    {
        $p = $this->partner();
        $service = app(PartnerService::class);
        $adj = $service->addAdjustment($p, ['date' => '2026-03-02', 'direction' => 'credit', 'amount' => '750', 'description' => 'Test'], $this->user);

        $service->reverseAdjustment($adj, 'Hatalı giriş', $this->user);
        $this->assertSame('0.00', $service->summary($p)['balance']);
        $this->expectError(fn () => $service->reverseAdjustment($adj, 'tekrar', $this->user), 'adjustment');

        $this->expectException(LogicException::class);
        PartnerAdjustment::first()->delete();
    }

    public function test_partner_category_of_transaction_cannot_be_switched(): void
    {
        $p = $this->partner();
        $t = $this->move('inflow', 'partner_capital', '1000', $p);

        $this->expectError(fn () => $this->tx->update($t, ['category_id' => $this->cat('commission_income')], $this->user), 'category_id');
    }

    // --- Tekrarlayan giderler ---

    private function rent(array $overrides = []): RecurringExpense
    {
        $e = new RecurringExpense(array_merge([
            'name' => 'Ofis kirası', 'category_id' => $this->cat('fx_rent'), 'amount' => '27000.00',
            'day_of_month' => 31, 'start_date' => '2026-01-01', 'account_id' => $this->bank->id,
        ], $overrides));
        $e->created_by = $this->user->id;
        $e->save();

        return $e;
    }

    public function test_due_date_and_validity_window(): void
    {
        $e = $this->rent(['end_date' => '2026-06-30']);

        $this->assertSame('2026-02-28', $e->dueDateFor(CarbonImmutable::parse('2026-02-01'))->toDateString());
        $this->assertSame('2026-03-31', $e->dueDateFor(CarbonImmutable::parse('2026-03-01'))->toDateString());
        $this->assertFalse($e->appliesTo(CarbonImmutable::parse('2025-12-01')));
        $this->assertTrue($e->appliesTo(CarbonImmutable::parse('2026-06-01')));
        $this->assertFalse($e->appliesTo(CarbonImmutable::parse('2026-07-01')));
    }

    public function test_recurring_expense_payment_once_per_month(): void
    {
        $this->move('inflow', 'commission_income', '100.000', null, '2026-01-01');
        $e = $this->rent();
        $service = app(ExpenseService::class);
        $feb = CarbonImmutable::parse('2026-02-01');

        $this->assertSame('overdue', $service->monthStatus($feb)->first()['status']);

        $payment = $service->pay($e, $feb, ['date' => '2026-02-27'], $this->user);
        $this->assertSame('27000.00', $payment->amount);
        $this->assertSame('fx_rent', $payment->category->code);
        $this->assertSame('2026-02-01', $payment->expense_period->toDateString());
        $this->assertSame('paid', $service->monthStatus($feb)->first()['status']);

        $this->expectError(fn () => $service->pay($e, $feb, ['date' => '2026-02-28'], $this->user), 'period');

        // Ödeme iptal edilirse ay yeniden ödenebilir.
        $this->tx->reverse($payment, '2026-02-28', 'Yanlış tutar', $this->user);
        $this->assertSame('overdue', $service->monthStatus($feb)->first()['status']);
        $service->pay($e, $feb, ['date' => '2026-02-28', 'amount' => '27.500,00'], $this->user);
        $this->assertSame('paid', $service->monthStatus($feb)->first()['status']);
    }

    public function test_expense_ratios_use_total_cash_outflow(): void
    {
        $this->move('inflow', 'commission_income', '100.000', null, '2026-03-01');
        $this->move('outflow', 'fx_rent', '27.000');
        $this->move('outflow', 'vx_electricity', '1.000');
        $this->move('outflow', 'premium_transfer', '2.000');
        $this->move('outflow', 'fx_rent', '50', null, '2026-04-01'); // dönem dışı

        $a = app(ExpenseService::class)->analysis(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertSame('30000.00', $a['total_outflow']);
        $this->assertSame('27000.00', $a['fixed']);
        $this->assertSame('1000.00', $a['variable']);
        $this->assertSame('2000.00', $a['other']);
        $this->assertSame('90.00', $a['fixed_ratio']);
        $this->assertSame('3.33', $a['variable_ratio']);
    }

    public function test_ratios_are_null_without_outflows(): void
    {
        $a = app(ExpenseService::class)->analysis(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $this->assertNull($a['fixed_ratio']);
        $this->assertSame('0.00', $a['total_outflow']);
    }

    // --- Ekranlar ve yetki ---

    public function test_viewer_can_view_but_not_write_sprint4_screens(): void
    {
        $p = $this->partner();
        $e = $this->rent();
        $this->actingAs(User::factory()->viewer()->create());

        foreach (['/partners', "/partners/{$p->id}", '/loans', '/expenses', '/expenses?month=2026-02'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/partners/create')->assertForbidden();
        $this->post('/partners', ['name' => 'X'])->assertForbidden();
        $this->post("/partners/{$p->id}/adjustments", ['date' => '2026-03-01', 'direction' => 'credit', 'amount' => '1', 'description' => 'xxx'])->assertForbidden();
        $this->get('/loans/create')->assertForbidden();
        $this->post('/loans', [])->assertForbidden();
        $this->get('/expenses/create')->assertForbidden();
        $this->post("/expenses/{$e->id}/pay", ['month' => '2026-02', 'date' => '2026-02-01', 'amount' => '1', 'account_id' => $this->bank->id])->assertForbidden();

        $this->assertSame(0, PartnerAdjustment::count());
        $this->assertSame(0, Transaction::count());
    }

    public function test_finance_creates_recurring_expense_and_loan_via_forms(): void
    {
        $this->actingAs($this->user);

        $this->post('/expenses', [
            'name' => 'Bina aidatı', 'category_id' => $this->cat('fx_building_fee'), 'amount' => '1.700',
            'day_of_month' => 5, 'start_date' => '2026-01-01', 'account_id' => $this->bank->id,
        ])->assertRedirect('/expenses');
        $this->assertSame('1700.00', RecurringExpense::sole()->amount);

        $this->post('/expenses', [
            'name' => 'Hatalı', 'category_id' => $this->cat('premium_transfer'), 'amount' => '10',
            'day_of_month' => 5, 'start_date' => '2026-01-01',
        ])->assertSessionHasErrors('category_id');

        $this->post('/loans', [
            'name' => 'İşletme Kredisi', 'bank_name' => 'Ziraat', 'account_id' => $this->bank->id,
            'start_date' => '2026-01-10', 'principal' => '250.000', 'annual_interest_rate' => '45,5',
            'term_months' => 24, 'first_due_date' => '2026-02-10', 'record_disbursement' => 1,
        ])->assertRedirect();
        $this->get('/loans')->assertSee('İşletme Kredisi')->assertSee('250.000,00 ₺');

        $this->post('/partners', ['name' => 'Ayşe Zafir', 'share_percent' => '50,5'])->assertRedirect();
        $this->assertSame('50.50', Partner::where('name', 'Ayşe Zafir')->value('share_percent'));
    }
}
