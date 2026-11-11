<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;

    private BalanceService $balances;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TransactionService::class);
        $this->balances = app(BalanceService::class);
        $this->user = User::factory()->finance()->create();
    }

    private function category(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function inflow(Account $to, string $amount, string $date = 'today', array $extra = []): Transaction
    {
        return $this->service->create(array_merge([
            'type' => 'inflow', 'date' => $date, 'amount' => $amount,
            'to_account_id' => $to->id, 'category_id' => $this->category('commission_income'),
        ], $extra), $this->user);
    }

    private function outflow(Account $from, string $amount, string $date = 'today', string $category = 'fx_rent'): Transaction
    {
        return $this->service->create([
            'type' => 'outflow', 'date' => $date, 'amount' => $amount,
            'from_account_id' => $from->id, 'category_id' => $this->category($category),
        ], $this->user);
    }

    private function transfer(Account $from, Account $to, string $amount, string $date = 'today'): Transaction
    {
        return $this->service->create([
            'type' => 'transfer', 'date' => $date, 'amount' => $amount,
            'from_account_id' => $from->id, 'to_account_id' => $to->id,
        ], $this->user);
    }

    private function balance(Account $account): string
    {
        return $this->balances->balance($account);
    }

    private function assertRejected(callable $action, string $field): void
    {
        $before = Transaction::count();
        try {
            $action();
            $this->fail("ValidationException bekleniyordu ({$field}).");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame($before, Transaction::count(), 'Reddedilen işlem kayıt bırakmamalı.');
    }

    // --- Bakiye ---

    public function test_inflow_outflow_and_transfer_update_balances(): void
    {
        $cash = Account::factory()->cash()->create();
        $bank = Account::factory()->bank()->create();

        $this->inflow($bank, '10.000,00');
        $this->outflow($bank, '27,50');
        $this->transfer($bank, $cash, '500');

        $this->assertSame('9472.50', $this->balance($bank));
        $this->assertSame('500.00', $this->balance($cash));
    }

    public function test_balance_is_exact_with_decimal_amounts(): void
    {
        $bank = Account::factory()->create();

        foreach (['0,10', '0,20', '0,10', '0,20', '0,10', '0,20'] as $amount) {
            $this->inflow($bank, $amount);
        }

        $this->assertSame('0.90', $this->balance($bank));
    }

    public function test_balance_as_of_date_excludes_later_transactions(): void
    {
        $bank = Account::factory()->create();
        $this->inflow($bank, '100', '2026-01-10');
        $this->inflow($bank, '50', '2026-01-20');

        $this->assertSame('0.00', $this->balances->balance($bank, now()->setDate(2026, 1, 9)));
        $this->assertSame('100.00', $this->balances->balance($bank, now()->setDate(2026, 1, 10)));
        $this->assertSame('150.00', $this->balances->balance($bank, now()->setDate(2026, 1, 31)));
    }

    public function test_summary_keeps_blocked_pos_out_of_available_cash(): void
    {
        $cash = Account::factory()->cash()->create();
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();

        $this->inflow($cash, '1.000');
        $this->inflow($bank, '5.000');
        $this->inflow($pos, '2.500', 'today', ['pos_settlement_date' => today()->addDays(2)->toDateString()]);

        $this->assertSame([
            'cash' => '1000.00',
            'bank' => '5000.00',
            'available' => '6000.00',
            'pos_blocked' => '2500.00',
            'total_liquidity' => '8500.00',
        ], $this->balances->summary());
    }

    public function test_transfer_does_not_change_total_liquidity(): void
    {
        $cash = Account::factory()->cash()->create();
        $bank = Account::factory()->bank()->create();
        $this->inflow($bank, '1000');
        $before = $this->balances->summary()['available'];

        $this->transfer($bank, $cash, '400');

        $this->assertSame($before, $this->balances->summary()['available']);
    }

    // --- Tutar ve tarih doğrulaması ---

    public static function invalidAmounts(): array
    {
        return [['0'], ['0,00'], ['-10'], ['10,555'], ['on bin'], ['10000000000000000']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $amount): void
    {
        $bank = Account::factory()->create();

        $this->assertRejected(fn () => $this->inflow($bank, $amount), 'amount');
    }

    public function test_future_dated_transaction_is_rejected(): void
    {
        $bank = Account::factory()->create();

        $this->assertRejected(fn () => $this->inflow($bank, '100', today()->addDay()->toDateString()), 'date');
    }

    // --- Negatif bakiye ---

    public function test_outflow_exceeding_balance_is_rejected(): void
    {
        $cash = Account::factory()->cash()->create();
        $this->inflow($cash, '100');

        $this->assertRejected(fn () => $this->outflow($cash, '100,01'), 'amount');
        $this->outflow($cash, '100');
        $this->assertSame('0.00', $this->balance($cash));
    }

    public function test_backdated_outflow_cannot_make_a_later_day_negative(): void
    {
        $cash = Account::factory()->cash()->create();
        $this->inflow($cash, '100', '2026-03-01');
        $this->outflow($cash, '80', '2026-03-03');

        // 2 Mart'ta 50 çıkış: o gün bakiye 50 olur ama 3 Mart'ta -30'a düşer.
        $this->assertRejected(fn () => $this->outflow($cash, '50', '2026-03-02'), 'amount');
        $this->assertSame('20.00', $this->balance($cash));
    }

    public function test_bank_with_overdraft_may_go_negative(): void
    {
        $bank = Account::factory()->bank()->create(['allow_negative' => true]);

        $this->outflow($bank, '250');

        $this->assertSame('-250.00', $this->balance($bank));
    }

    // --- Hesap ve kategori kuralları ---

    public function test_category_must_match_direction_and_be_a_leaf(): void
    {
        $bank = Account::factory()->create();
        $this->inflow($bank, '1000');

        // Giriş kategorisiyle çıkış yapılamaz.
        $this->assertRejected(fn () => $this->outflow($bank, '10', 'today', 'commission_income'), 'category_id');
        // Alt kategorisi olan üst kategori ("Sabit giderler") seçilemez.
        $this->assertRejected(fn () => $this->outflow($bank, '10', 'today', 'fixed_expense'), 'category_id');
        // Kategorisiz giriş olmaz.
        $this->assertRejected(fn () => $this->service->create(['type' => 'inflow', 'date' => 'today', 'amount' => '1', 'to_account_id' => $bank->id], $this->user), 'category_id');
    }

    public function test_inactive_account_cannot_receive_transactions(): void
    {
        $bank = Account::factory()->create(['is_active' => false]);

        $this->assertRejected(fn () => $this->inflow($bank, '100'), 'to_account_id');
    }

    public function test_pos_rules(): void
    {
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();
        $this->inflow($bank, '1000');
        $this->inflow($pos, '300', 'today', ['pos_settlement_date' => today()->toDateString()]);

        // POS'tan doğrudan ödeme ve POS'a transfer yapılamaz.
        $this->assertRejected(fn () => $this->outflow($pos, '10'), 'from_account_id');
        $this->assertRejected(fn () => $this->transfer($bank, $pos, '10'), 'to_account_id');
        // POS tahsilatında valör tarihi zorunlu, POS dışı girişte yasak.
        $this->assertRejected(fn () => $this->inflow($pos, '10'), 'pos_settlement_date');
        $this->assertRejected(fn () => $this->inflow($bank, '10', 'today', ['pos_settlement_date' => today()->toDateString()]), 'pos_settlement_date');
        // Valör, tahsilattan önce olamaz.
        $this->assertRejected(fn () => $this->inflow($pos, '10', 'today', ['pos_settlement_date' => today()->subDay()->toDateString()]), 'pos_settlement_date');
    }

    public function test_transfer_to_same_account_is_rejected(): void
    {
        $bank = Account::factory()->create();
        $this->inflow($bank, '100');

        $this->assertRejected(fn () => $this->transfer($bank, $bank, '10'), 'to_account_id');
    }

    // --- POS aktarımı ---

    public function test_pos_settlement_moves_money_to_bank_without_counting_twice(): void
    {
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();
        $inflow = $this->inflow($pos, '2.500', today()->subDays(2)->toDateString(), ['pos_settlement_date' => today()->toDateString()]);

        $transfer = $this->service->settlePos($inflow, null, $this->user);

        $this->assertSame('transfer', $transfer->type->value);
        $this->assertSame($inflow->id, $transfer->settles_transaction_id);
        $this->assertSame('0.00', $this->balance($pos));
        $this->assertSame('2500.00', $this->balance($bank));
        $summary = $this->balances->summary();
        $this->assertSame('2500.00', $summary['available']);
        $this->assertSame('2500.00', $summary['total_liquidity'], 'Tutar likiditeye iki kez eklenmemeli.');
        // Nakit girişi olarak yalnızca bir kez görünür.
        $this->assertSame('2500.00', Transaction::where('type', 'inflow')->sum('amount'));
    }

    public function test_pos_inflow_cannot_be_settled_twice(): void
    {
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();
        $inflow = $this->inflow($pos, '100', 'today', ['pos_settlement_date' => today()->toDateString()]);
        $this->service->settlePos($inflow, null, $this->user);

        $this->assertRejected(fn () => $this->service->settlePos($inflow, null, $this->user), 'transaction');
        $this->assertSame('100.00', $this->balance($bank));
    }

    public function test_settle_due_only_moves_matured_pos_inflows(): void
    {
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();
        $this->inflow($pos, '100', today()->subDays(3)->toDateString(), ['pos_settlement_date' => today()->subDay()->toDateString()]);
        $this->inflow($pos, '200', today()->subDays(1)->toDateString(), ['pos_settlement_date' => today()->toDateString()]);
        $this->inflow($pos, '400', 'today', ['pos_settlement_date' => today()->addDays(5)->toDateString()]);

        $transfers = $this->service->settleDuePos($this->user);

        $this->assertCount(2, $transfers);
        $this->assertSame('300.00', $this->balance($bank));
        $this->assertSame('400.00', $this->balance($pos));
        $this->assertCount(0, $this->service->settleDuePos($this->user), 'İkinci çalıştırma hiçbir şey aktarmamalı.');
    }

    public function test_settled_pos_inflow_requires_reversing_the_transfer_first(): void
    {
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create();
        $inflow = $this->inflow($pos, '100', 'today', ['pos_settlement_date' => today()->toDateString()]);
        $transfer = $this->service->settlePos($inflow, null, $this->user);

        $this->assertRejected(fn () => $this->service->reverse($inflow, 'today', 'hata', $this->user), 'transaction');

        // Transfer iptal edilince giriş tekrar bekleyen olur ve yeniden aktarılabilir.
        $this->service->reverse($transfer, 'today', 'yanlış banka', $this->user);
        $this->assertSame('100.00', $this->balance($pos));
        $this->assertSame('0.00', $this->balance($bank));
        $this->assertTrue($this->service->pendingPosInflows()->whereKey($inflow->id)->exists());

        $this->service->settlePos($inflow, null, $this->user);
        $this->assertSame('100.00', $this->balance($bank));
    }

    // --- Ters kayıt ---

    public function test_reversal_creates_negative_entry_and_keeps_original(): void
    {
        $bank = Account::factory()->create();
        $original = $this->inflow($bank, '1.000', '2026-02-10');

        $reversal = $this->service->reverse($original, '2026-02-15', 'Mükerrer giriş', $this->user);

        $this->assertSame('-1000.00', $reversal->amount);
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame($original->category_id, $reversal->category_id);
        $this->assertSame('1000.00', $original->fresh()->amount, 'Orijinal kayıt değişmemeli.');
        $this->assertSame('0.00', $this->balance($bank));
        // Geçmiş dönem değişmez: iptal tarihinden önceki bakiye korunur.
        $this->assertSame('1000.00', $this->balances->balance($bank, now()->setDate(2026, 2, 14)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'reversed', 'auditable_id' => $original->id]);
    }

    public function test_reversal_rules(): void
    {
        $bank = Account::factory()->create();
        $original = $this->inflow($bank, '100', '2026-02-10');

        $this->assertRejected(fn () => $this->service->reverse($original, '2026-02-09', 'erken', $this->user), 'date');

        $reversal = $this->service->reverse($original, '2026-02-10', 'hata', $this->user);
        $this->assertRejected(fn () => $this->service->reverse($original, 'today', 'tekrar', $this->user), 'transaction');
        $this->assertRejected(fn () => $this->service->reverse($reversal, 'today', 'tersin tersi', $this->user), 'transaction');
    }

    public function test_reversing_inflow_already_spent_is_rejected(): void
    {
        $cash = Account::factory()->cash()->create();
        $inflow = $this->inflow($cash, '100');
        $this->outflow($cash, '70');

        $this->assertRejected(fn () => $this->service->reverse($inflow, 'today', 'hata', $this->user), 'transaction');
    }

    public function test_opening_balance_is_unique_per_account_until_reversed(): void
    {
        $bank = Account::factory()->create();
        $open = fn () => $this->service->create(['type' => 'opening', 'date' => '2026-01-01', 'amount' => '50.000', 'to_account_id' => $bank->id], $this->user);

        $first = $open();
        $this->assertRejected($open, 'to_account_id');

        $this->service->reverse($first, '2026-01-01', 'yanlış tutar', $this->user);
        $open();
        $this->assertSame('50000.00', $this->balance($bank));
    }

    // --- Düzenleme ve silme ---

    public function test_update_changes_only_descriptive_fields(): void
    {
        $bank = Account::factory()->create();
        $tx = $this->inflow($bank, '100');

        $this->service->update($tx, [
            'description' => 'Ocak komisyonu', 'amount' => '999', 'date' => '2020-01-01',
            'category_id' => $this->category('customer_collection'),
        ], $this->user);

        $tx->refresh();
        $this->assertSame('100.00', $tx->amount);
        $this->assertSame(today()->toDateString(), $tx->date->toDateString());
        $this->assertSame('Ocak komisyonu', $tx->description);
        $this->assertSame($this->category('customer_collection'), $tx->category_id);

        $log = AuditLog::where('action', 'updated')->where('auditable_id', $tx->id)->sole();
        $this->assertSame(['category_id', 'description'], array_keys($log->new_values));
    }

    public function test_reversed_transaction_cannot_be_edited(): void
    {
        $bank = Account::factory()->create();
        $tx = $this->inflow($bank, '100');
        $this->service->reverse($tx, 'today', 'hata', $this->user);

        $this->assertRejected(fn () => $this->service->update($tx->fresh(), ['description' => 'x'], $this->user), 'transaction');
    }

    public function test_transactions_cannot_be_deleted(): void
    {
        $tx = $this->inflow(Account::factory()->create(), '100');

        $this->expectException(LogicException::class);
        $tx->delete();
    }

    public function test_database_rejects_invalid_rows_even_without_the_service(): void
    {
        $bank = Account::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('transactions')->insert([
            'type' => 'inflow', 'date' => today(), 'amount' => '-5.00', 'to_account_id' => $bank->id,
            'category_id' => $this->category('commission_income'), 'created_by' => $this->user->id,
        ]);
    }
}
