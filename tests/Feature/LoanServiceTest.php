<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\LoanService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoanService $loans;

    private User $user;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loans = app(LoanService::class);
        $this->user = User::factory()->finance()->create();
        $this->bank = Account::factory()->bank()->create();
    }

    private function makeLoan(array $overrides = []): Loan
    {
        return $this->loans->create(array_merge([
            'name' => 'Ticari Kredi', 'bank_name' => 'Garanti', 'account_id' => $this->bank->id,
            'start_date' => '2026-01-15', 'principal' => '100.000,00', 'annual_interest_rate' => '12',
            'term_months' => 12, 'first_due_date' => '2026-02-15', 'record_disbursement' => true,
        ], $overrides), $this->user);
    }

    private function balance(): string
    {
        return app(BalanceService::class)->balance($this->bank);
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

    public function test_disbursement_is_a_financing_cash_inflow(): void
    {
        $loan = $this->makeLoan();

        $inflow = Transaction::where('loan_id', $loan->id)->sole();
        $this->assertSame('inflow', $inflow->type->value);
        $this->assertSame('loan_received', $inflow->category->code);
        $this->assertSame('financing', $inflow->category->activity->value);
        $this->assertSame('100000.00', $this->balance());
        $this->assertCount(12, $loan->installments);
    }

    public function test_installment_payment_records_principal_and_interest_separately(): void
    {
        $loan = $this->makeLoan();
        $first = $loan->installments()->first();

        $txs = $this->loans->payInstallment($first, '2026-02-15', $this->bank->id, $this->user);

        $this->assertCount(2, $txs);
        $byCode = $txs->keyBy(fn ($t) => $t->category->code);
        $this->assertSame('7884.88', $byCode['loan_principal']->amount);
        $this->assertSame('1000.00', $byCode['loan_interest']->amount);
        foreach ($txs as $t) {
            $this->assertSame('financing', $t->category->activity->value);
            $this->assertSame($first->id, $t->loan_installment_id);
        }
        $this->assertSame('91115.12', $this->balance());

        $summary = $this->loans->summary($loan);
        $this->assertSame('92115.12', $summary['remaining_principal']);
        $this->assertSame('7884.88', $summary['paid_principal']);
        $this->assertSame('1000.00', $summary['paid_interest']);
        $this->assertSame(1, $summary['paid_count']);
        $this->assertSame(2, $summary['next']->number);
    }

    public function test_installments_must_be_paid_in_order_and_only_once(): void
    {
        $loan = $this->makeLoan();
        [$first, $second] = $loan->installments()->take(2)->get();

        $this->expectError(fn () => $this->loans->payInstallment($second, '2026-02-15', $this->bank->id, $this->user), 'installment');
        $this->loans->payInstallment($first, '2026-02-15', $this->bank->id, $this->user);
        $this->expectError(fn () => $this->loans->payInstallment($first, '2026-02-16', $this->bank->id, $this->user), 'installment');
    }

    public function test_payment_is_atomic_when_balance_is_insufficient(): void
    {
        $loan = $this->makeLoan(['record_disbursement' => false]);
        app(TransactionService::class)->create([
            'type' => 'inflow', 'date' => '2026-01-20', 'amount' => '8000', 'to_account_id' => $this->bank->id,
            'category_id' => Category::where('code', 'commission_income')->value('id'),
        ], $this->user);

        // 8.000 anaparayı (7.884,88) karşılar ama faizi karşılamaz: hiçbir kayıt kalmamalı.
        $this->expectError(fn () => $this->loans->payInstallment($loan->installments()->first(), '2026-02-15', $this->bank->id, $this->user), 'amount');
        $this->assertSame(0, Transaction::whereNotNull('loan_installment_id')->count());
        $this->assertSame('8000.00', $this->balance());
    }

    public function test_reversing_a_payment_reverses_both_lines_and_allows_repayment(): void
    {
        $loan = $this->makeLoan();
        [$first, $second] = $loan->installments()->take(2)->get();
        $this->loans->payInstallment($first, '2026-02-15', $this->bank->id, $this->user);
        $this->loans->payInstallment($second, '2026-03-15', $this->bank->id, $this->user);

        $this->expectError(fn () => $this->loans->reversePayment($first, '2026-03-20', 'hata', $this->user), 'installment');

        $this->loans->reversePayment($second, '2026-03-20', 'yanlış hesap', $this->user);
        $this->assertSame(4, Transaction::where('loan_installment_id', $second->id)->count(), '2 ödeme + 2 ters kayıt');
        $this->assertFalse($this->loans->isPaid($second));
        $this->assertSame('92115.12', $this->loans->summary($loan)['remaining_principal']);

        $this->loans->payInstallment($second, '2026-03-20', $this->bank->id, $this->user);
        $this->assertTrue($this->loans->isPaid($second));
    }

    public function test_loan_transactions_cannot_be_created_or_reversed_from_generic_screens(): void
    {
        $loan = $this->makeLoan();
        $service = app(TransactionService::class);
        $inflow = Transaction::where('loan_id', $loan->id)->sole();

        $this->expectError(fn () => $service->reverse($inflow, '2026-02-01', 'x', $this->user), 'transaction');
        $this->expectError(fn () => $service->create([
            'type' => 'outflow', 'date' => '2026-02-01', 'amount' => '100', 'from_account_id' => $this->bank->id,
            'category_id' => Category::where('code', 'loan_interest')->value('id'),
        ], $this->user), 'category_id');

        $this->assertArrayNotHasKey('Kredi taksitleri', Category::groupedOptions('outflow')->all());
    }

    public function test_unpaid_installment_can_be_adjusted_to_bank_plan(): void
    {
        $loan = $this->makeLoan();
        $last = $loan->installments()->get()->last();

        $this->loans->updateInstallment($last, ['principal' => $last->principal, 'interest' => '150,25', 'due_date' => '2027-01-20'], $this->user);
        $this->assertSame('150.25', $last->fresh()->interest);
        $this->assertSame('0.00', $this->loans->summary($loan)['plan_difference']);

        $this->loans->updateInstallment($last, ['principal' => '1,00', 'interest' => '0', 'due_date' => '2027-01-20'], $this->user);
        $this->assertNotSame('0.00', $this->loans->summary($loan)['plan_difference'], 'Plan farkı raporlanmalı');

        $first = $loan->installments()->first();
        $this->loans->payInstallment($first, '2026-02-15', $this->bank->id, $this->user);
        $this->expectError(fn () => $this->loans->updateInstallment($first, ['principal' => '1', 'interest' => '1', 'due_date' => '2026-02-15'], $this->user), 'installment');
    }

    public function test_zero_interest_loan_records_only_principal(): void
    {
        $loan = $this->makeLoan(['annual_interest_rate' => '0', 'term_months' => 4, 'principal' => '40.000']);

        $txs = $this->loans->payInstallment($loan->installments()->first(), '2026-02-15', $this->bank->id, $this->user);

        $this->assertCount(1, $txs);
        $this->assertSame('loan_principal', $txs->first()->category->code);
    }

    public function test_invalid_loan_inputs_are_rejected(): void
    {
        $this->expectError(fn () => $this->makeLoan(['principal' => '0']), 'principal');
        $this->expectError(fn () => $this->makeLoan(['annual_interest_rate' => '-1']), 'annual_interest_rate');
        $this->expectError(fn () => $this->makeLoan(['first_due_date' => '2026-01-15']), 'first_due_date');
        $cash = Account::factory()->cash()->create();
        $this->expectError(fn () => $this->makeLoan(['account_id' => $cash->id]), 'account_id');
        $this->assertSame(0, Loan::count());
    }
}
