<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\ReceivableService;
use App\Services\TransactionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ReceivableTest extends TestCase
{
    use RefreshDatabase;

    private ReceivableService $service;

    private User $user;

    private Account $bank;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReceivableService::class);
        $this->user = User::factory()->finance()->create();
        $this->bank = Account::factory()->bank()->create();
        $this->customer = $this->makeCustomer('Ayşe Yılmaz');
    }

    private function makeCustomer(string $name, array $attrs = []): Customer
    {
        $c = new Customer(array_merge(['name' => $name], $attrs));
        $c->created_by = $this->user->id;
        $c->save();

        return $c;
    }

    private function receivable(string $amount = '1.000,00', array $overrides = []): Receivable
    {
        return $this->service->create(array_merge([
            'customer_id' => $this->customer->id, 'reference_no' => 'POL-2026-001', 'insurance_company' => 'Anadolu Sigorta',
            'amount' => $amount, 'issue_date' => today()->subDays(40)->toDateString(), 'due_date' => today()->subDays(10)->toDateString(),
        ], $overrides), $this->user);
    }

    private function collect(Receivable $r, string $amount, array $extra = []): Transaction
    {
        return $this->service->collect($r, array_merge([
            'date' => today()->toDateString(), 'amount' => $amount, 'account_id' => $this->bank->id,
        ], $extra), $this->user);
    }

    private function expectError(callable $fn, string $field): void
    {
        $before = Transaction::count();
        try {
            $fn();
            $this->fail("ValidationException bekleniyordu ({$field})");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame($before, Transaction::count());
    }

    private function fresh(Receivable $r): Receivable
    {
        return Receivable::withCollected()->find($r->id);
    }

    // --- Kısmi / tam tahsilat ---

    public function test_partial_collection_updates_remaining_and_cash(): void
    {
        $r = $this->receivable();

        $tx = $this->collect($r, '400');

        $r = $this->fresh($r);
        $this->assertSame('400.00', $r->collectedAmount());
        $this->assertSame('600.00', $r->remainingAmount());
        $this->assertSame('partial', $r->status());
        $this->assertSame('inflow', $tx->type->value);
        $this->assertSame('customer_collection', $tx->category->code);
        $this->assertSame($r->id, $tx->receivable_id);
        $this->assertSame('Ayşe Yılmaz', $tx->counterparty);
        $this->assertSame('400.00', app(BalanceService::class)->balance($this->bank));
    }

    public function test_full_collection_in_parts(): void
    {
        $r = $this->receivable();

        $this->collect($r, '333,33');
        $this->collect($r, '333,33');
        $this->collect($r, '333,33');
        $this->assertSame('0.01', $this->fresh($r)->remainingAmount());
        $this->collect($r, '0,01');

        $r = $this->fresh($r);
        $this->assertSame('paid', $r->status());
        $this->assertSame('0.00', $r->remainingAmount());
        $this->assertFalse($r->isOverdue(), 'Tahsil edilmiş alacak gecikmiş sayılmaz');
    }

    public function test_exact_remaining_is_accepted_and_one_kurus_more_is_rejected(): void
    {
        $r = $this->receivable();
        $this->collect($r, '400');

        $this->expectError(fn () => $this->collect($r, '600,01'), 'amount');
        $this->collect($r, '600,00');
        $this->expectError(fn () => $this->collect($r, '0,01'), 'amount');
        $this->assertSame('1000.00', $this->fresh($r)->collectedAmount());
    }

    public function test_invalid_collection_amounts_and_dates(): void
    {
        $r = $this->receivable();

        $this->expectError(fn () => $this->collect($r, '0'), 'amount');
        $this->expectError(fn () => $this->collect($r, '-5'), 'amount');
        $this->expectError(fn () => $this->collect($r, '10,555'), 'amount');
        $this->expectError(fn () => $this->collect($r, '10', ['date' => today()->subDays(41)->toDateString()]), 'date');
        $this->expectError(fn () => $this->collect($r, '10', ['date' => today()->addDay()->toDateString()]), 'date');
    }

    public function test_pos_collection_stays_blocked_until_settlement(): void
    {
        $pos = Account::factory()->pos($this->bank)->create();
        $r = $this->receivable();

        $this->expectError(fn () => $this->collect($r, '500', ['account_id' => $pos->id]), 'pos_settlement_date');
        $this->collect($r, '500', ['account_id' => $pos->id, 'pos_settlement_date' => today()->addDays(3)->toDateString()]);

        $summary = app(BalanceService::class)->summary();
        $this->assertSame('500.00', $summary['pos_blocked']);
        $this->assertSame('0.00', $summary['available']);
        $this->assertSame('500.00', $this->fresh($r)->remainingAmount(), 'Alacak açısından tahsil edilmiş sayılır');
    }

    // --- Veritabanı koruması ---

    public function test_database_trigger_blocks_over_collection_even_without_service(): void
    {
        $r = $this->receivable('100');
        $this->collect($r, '100');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('alacak tutarını');
        DB::table('transactions')->insert([
            'type' => 'inflow', 'date' => today(), 'amount' => '0.01', 'to_account_id' => $this->bank->id,
            'category_id' => Category::where('code', 'customer_collection')->value('id'),
            'receivable_id' => $r->id, 'created_by' => $this->user->id,
        ]);
    }

    public function test_database_trigger_blocks_outflow_linked_to_receivable(): void
    {
        $r = $this->receivable('100');
        $this->collect($r, '100');

        $this->expectException(QueryException::class);
        DB::table('transactions')->insert([
            'type' => 'outflow', 'date' => today(), 'amount' => '10', 'from_account_id' => $this->bank->id,
            'category_id' => Category::where('code', 'other_outflow')->value('id'),
            'receivable_id' => $r->id, 'created_by' => $this->user->id,
        ]);
    }

    public function test_database_trigger_blocks_reducing_amount_below_collected(): void
    {
        $r = $this->receivable('1000');
        $this->collect($r, '700');

        $this->expectException(QueryException::class);
        DB::table('receivables')->where('id', $r->id)->update(['amount' => '699.99']);
    }

    // --- Düzenleme, iptal ---

    public function test_amount_cannot_be_reduced_below_collected(): void
    {
        $r = $this->receivable();
        $this->collect($r, '700');
        $data = ['reference_no' => 'POL-2026-001', 'issue_date' => $r->issue_date->toDateString(), 'due_date' => $r->due_date->toDateString()];

        $this->expectError(fn () => $this->service->update($r, $data + ['amount' => '699,99'], $this->user), 'amount');
        $this->service->update($r, $data + ['amount' => '700'], $this->user);

        $this->assertSame('paid', $this->fresh($r)->status());
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_type' => 'Receivable', 'auditable_id' => $r->id]);
    }

    public function test_reversing_a_collection_restores_the_balance_due(): void
    {
        $r = $this->receivable();
        $tx = $this->collect($r, '1000');
        $this->assertSame('paid', $this->fresh($r)->status());

        app(TransactionService::class)->reverse($tx, today()->toDateString(), 'Karşılıksız çek', $this->user);

        $r = $this->fresh($r);
        $this->assertSame('open', $r->status());
        $this->assertSame('1000.00', $r->remainingAmount());
        $this->assertTrue($r->isOverdue());
    }

    public function test_cancel_rules(): void
    {
        $r = $this->receivable();
        $tx = $this->collect($r, '100');

        $this->expectError(fn () => $this->service->cancel($r, 'Poliçe iptal', $this->user), 'receivable');

        app(TransactionService::class)->reverse($tx, today()->toDateString(), 'İade', $this->user);
        $this->service->cancel($r, 'Poliçe iptal', $this->user);

        $r = $this->fresh($r);
        $this->assertSame('cancelled', $r->status());
        $this->assertFalse($r->isOverdue());
        $this->expectError(fn () => $this->collect($r, '10'), 'receivable');
        $this->expectError(fn () => $this->service->cancel($r, 'tekrar', $this->user), 'receivable');
    }

    public function test_receivable_validation(): void
    {
        $this->expectError(fn () => $this->receivable('0'), 'amount');
        $this->expectError(fn () => $this->receivable('100', ['due_date' => today()->subDays(41)->toDateString()]), 'due_date');
        $this->expectError(fn () => $this->receivable('100', ['issue_date' => today()->addDay()->toDateString(), 'due_date' => today()->addDays(5)->toDateString()]), 'issue_date');

        $passive = $this->makeCustomer('Pasif Müşteri');
        $passive->forceFill(['is_active' => false])->save();
        $this->expectError(fn () => $this->receivable('100', ['customer_id' => $passive->id]), 'customer_id');
        $this->assertSame(0, Receivable::count());
    }

    public function test_receivables_and_collections_are_not_deletable_or_recategorizable(): void
    {
        $r = $this->receivable();
        $tx = $this->collect($r, '100');

        try {
            app(TransactionService::class)->update($tx, ['category_id' => Category::where('code', 'other_inflow')->value('id')], $this->user);
            $this->fail('Kategori değişikliği engellenmeliydi');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_id', $e->errors());
        }

        $this->expectException(LogicException::class);
        $r->delete();
    }

    // --- Ekranlar ---

    public function test_list_filters_and_totals(): void
    {
        $open = $this->receivable('1.000', ['reference_no' => 'POL-OPEN']);
        $partial = $this->receivable('2.000', ['reference_no' => 'POL-PART']);
        $paid = $this->receivable('500', ['reference_no' => 'POL-PAID', 'due_date' => today()->addDays(5)->toDateString()]);
        $future = $this->receivable('300', ['reference_no' => 'POL-FUTURE', 'due_date' => today()->addDays(5)->toDateString()]);
        $this->collect($partial, '750');
        $this->collect($paid, '500');

        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/receivables')->assertOk()
            ->assertSee('POL-OPEN')->assertSee('POL-PART')->assertSee('POL-FUTURE')->assertDontSee('POL-PAID')
            ->assertSee('3.300,00 ₺')   // 1.000 + 2.000 + 300
            ->assertSee('2.550,00 ₺');  // kalan: 1.000 + 1.250 + 300
        $this->get('/receivables?status=overdue')->assertSee('POL-OPEN')->assertSee('POL-PART')->assertDontSee('POL-FUTURE');
        $this->get('/receivables?status=partial')->assertSee('POL-PART')->assertDontSee('POL-OPEN');
        $this->get('/receivables?status=paid')->assertSee('POL-PAID')->assertDontSee('POL-OPEN');
        $this->get('/receivables?q=ayşe')->assertSee('POL-OPEN');
        $this->get("/receivables/{$partial->id}")->assertOk()->assertSee('1.250,00 ₺')->assertDontSee('Tahsilatı kaydet');
        $this->get("/customers/{$this->customer->id}")->assertOk()->assertSee('POL-PAID');
        $this->get('/customers')->assertOk()->assertSee('Ayşe Yılmaz')->assertSee('2.550,00 ₺');
    }

    public function test_viewer_cannot_write(): void
    {
        $r = $this->receivable();
        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/receivables/create')->assertForbidden();
        $this->post('/receivables', [])->assertForbidden();
        $this->put("/receivables/{$r->id}", [])->assertForbidden();
        $this->post("/receivables/{$r->id}/collect", ['date' => today()->toDateString(), 'amount' => '10', 'account_id' => $this->bank->id])->assertForbidden();
        $this->post("/receivables/{$r->id}/cancel", ['reason' => 'xxx'])->assertForbidden();
        $this->get('/customers/create')->assertForbidden();
        $this->post('/customers', ['type' => 'individual', 'name' => 'X'])->assertForbidden();

        $this->assertSame(0, Transaction::count());
        $this->assertNull($r->fresh()->cancelled_at);
    }

    public function test_finance_collects_via_form_and_sees_overpayment_error(): void
    {
        $r = $this->receivable();
        $this->actingAs($this->user);

        $this->post("/receivables/{$r->id}/collect", ['date' => today()->toDateString(), 'amount' => '400,00', 'account_id' => $this->bank->id])
            ->assertRedirect("/receivables/{$r->id}");
        $this->from("/receivables/{$r->id}")
            ->post("/receivables/{$r->id}/collect", ['date' => today()->toDateString(), 'amount' => '700', 'account_id' => $this->bank->id])
            ->assertSessionHasErrors(['amount' => 'Tahsilat tutarı kalan alacağı (600,00 ₺) aşamaz.']);

        $this->assertSame('400.00', $this->fresh($r)->collectedAmount());
    }

    public function test_customer_tax_number_is_encrypted_and_masked(): void
    {
        $this->actingAs($this->user)->post('/customers', [
            'type' => 'individual', 'name' => 'Mehmet Öz', 'tax_number' => '123 456 789 01', 'phone' => '0532 000 00 00',
        ])->assertRedirect();

        $c = Customer::where('name', 'Mehmet Öz')->sole();
        $this->assertSame('12345678901', $c->tax_number);
        $this->assertStringNotContainsString('12345678901', DB::table('customers')->where('id', $c->id)->value('tax_number'));
        $this->get("/customers/{$c->id}")->assertSee('********901')->assertDontSee('12345678901');

        $this->post('/customers', ['type' => 'individual', 'name' => 'Hatalı', 'tax_number' => '123'])->assertSessionHasErrors('tax_number');
    }
}
