<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinanceScreensTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_IBAN = 'TR330006100519786457841326';

    private function seedBankWithInflow(User $user, string $amount = '1000'): array
    {
        $bank = Account::factory()->bank()->create(['name' => 'Garanti Vadesiz']);
        $tx = app(TransactionService::class)->create([
            'type' => 'inflow', 'date' => 'today', 'amount' => $amount, 'to_account_id' => $bank->id,
            'category_id' => Category::where('code', 'commission_income')->value('id'), 'description' => 'Ocak komisyonu',
        ], $user);

        return [$bank, $tx];
    }

    // --- Yetki ---

    public function test_viewer_can_view_but_not_write(): void
    {
        $viewer = User::factory()->viewer()->create();
        [$bank, $tx] = $this->seedBankWithInflow(User::factory()->finance()->create());
        $this->actingAs($viewer);

        foreach (['/accounts', "/accounts/{$bank->id}", '/transactions?type=inflow', "/transactions/{$tx->id}", '/pos'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/accounts/create')->assertForbidden();
        $this->post('/accounts', ['type' => 'cash', 'name' => 'X', 'currency' => 'TRY'])->assertForbidden();
        $this->get("/accounts/{$bank->id}/edit")->assertForbidden();
        $this->get('/transactions/create?type=inflow')->assertForbidden();
        $this->post('/transactions', ['type' => 'inflow', 'date' => today()->toDateString(), 'amount' => '5', 'to_account_id' => $bank->id])->assertForbidden();
        $this->patch("/transactions/{$tx->id}", ['description' => 'x'])->assertForbidden();
        $this->post("/transactions/{$tx->id}/reverse", ['date' => today()->toDateString(), 'reason' => 'deneme'])->assertForbidden();
        $this->post('/pos/settle-due')->assertForbidden();
        $this->post("/pos/{$tx->id}/settle")->assertForbidden();

        $this->assertSame(1, Transaction::count());
        $this->assertSame('Ocak komisyonu', $tx->fresh()->description);
    }

    public function test_viewer_does_not_see_write_buttons(): void
    {
        [$bank, $tx] = $this->seedBankWithInflow(User::factory()->finance()->create());

        $this->actingAs(User::factory()->viewer()->create())
            ->get("/transactions/{$tx->id}")
            ->assertDontSee('Ters kayıt oluştur')
            ->assertDontSee('Düzenle');
    }

    public static function writers(): array
    {
        return [['admin'], ['finance']];
    }

    #[DataProvider('writers')]
    public function test_admin_and_finance_can_record_inflow_with_turkish_amount(string $role): void
    {
        $user = User::factory()->{$role}()->create();
        $bank = Account::factory()->bank()->create();

        $this->actingAs($user)->post('/transactions', [
            'type' => 'inflow', 'date' => today()->toDateString(), 'amount' => '27.000,50',
            'to_account_id' => $bank->id, 'category_id' => Category::where('code', 'commission_income')->value('id'),
            'counterparty' => 'Anadolu Sigorta', 'reference_no' => 'DK-001',
        ])->assertRedirect('/transactions?type=inflow');

        $tx = Transaction::sole();
        $this->assertSame('27000.50', $tx->amount);
        $this->assertSame($user->id, $tx->created_by);
        $this->assertSame('TRY', $tx->currency);
    }

    public function test_validation_errors_are_shown_and_nothing_is_saved(): void
    {
        $bank = Account::factory()->bank()->create();

        $this->actingAs(User::factory()->finance()->create())
            ->from('/transactions/create?type=outflow')
            ->post('/transactions', [
                'type' => 'outflow', 'date' => today()->toDateString(), 'amount' => '500',
                'from_account_id' => $bank->id, 'category_id' => Category::where('code', 'fx_rent')->value('id'),
            ])
            ->assertRedirect('/transactions/create?type=outflow')
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Transaction::count());
    }

    // --- Ekranlar ---

    public function test_transaction_list_shows_filtered_total(): void
    {
        $user = User::factory()->finance()->create();
        $this->seedBankWithInflow($user, '1.500,25');
        [$bank2] = [Account::factory()->bank()->create()];
        app(TransactionService::class)->create([
            'type' => 'inflow', 'date' => 'today', 'amount' => '499,75', 'to_account_id' => $bank2->id,
            'category_id' => Category::where('code', 'customer_collection')->value('id'),
        ], $user);

        $this->actingAs($user)->get('/transactions?type=inflow')
            ->assertOk()->assertSee('2.000,00 ₺');

        $this->actingAs($user)->get('/transactions?type=inflow&account_id='.$bank2->id)
            ->assertOk()->assertSee('499,75 ₺')->assertDontSee('2.000,00 ₺');
    }

    public function test_account_statement_shows_running_balance(): void
    {
        $user = User::factory()->finance()->create();
        [$bank] = $this->seedBankWithInflow($user, '1000');
        app(TransactionService::class)->create([
            'type' => 'outflow', 'date' => 'today', 'amount' => '27.000', 'from_account_id' => $bank->id,
            'category_id' => Category::where('code', 'fx_rent')->value('id'),
        ], tap($user, fn () => $bank->forceFill(['allow_negative' => true])->save()));

        $this->actingAs($user)->get("/accounts/{$bank->id}")
            ->assertOk()
            ->assertSeeInOrder(['Devreden bakiye', '0,00 ₺', '1.000,00 ₺', '-27.000,00 ₺', '-26.000,00 ₺', 'Dönem sonu bakiye', '-26.000,00 ₺']);
    }

    public function test_pos_page_lists_pending_and_settles(): void
    {
        $user = User::factory()->finance()->create();
        $bank = Account::factory()->bank()->create();
        $pos = Account::factory()->pos($bank)->create(['name' => 'Garanti POS']);
        $inflow = app(TransactionService::class)->create([
            'type' => 'inflow', 'date' => 'today', 'amount' => '750', 'to_account_id' => $pos->id,
            'category_id' => Category::where('code', 'customer_collection')->value('id'),
            'pos_settlement_date' => today()->toDateString(),
        ], $user);

        $this->actingAs($user)->get('/pos')->assertOk()->assertSee('Garanti POS')->assertSee('Vadesi gelenleri aktar (1)');

        $this->post('/pos/settle-due')->assertRedirect('/pos')->assertSessionHas('status', '1 POS girişi bankaya aktarıldı.');
        $this->assertTrue(Transaction::where('settles_transaction_id', $inflow->id)->exists());
        $this->get('/pos')->assertSee('Bekleyen POS tahsilatı yok.');
    }

    public function test_reverse_via_screen(): void
    {
        $user = User::factory()->finance()->create();
        [, $tx] = $this->seedBankWithInflow($user);

        $this->actingAs($user)->post("/transactions/{$tx->id}/reverse", ['date' => today()->toDateString(), 'reason' => 'Mükerrer'])
            ->assertRedirect("/transactions/{$tx->id}");

        $this->get("/transactions/{$tx->id}")->assertSee('Bu hareket')->assertSee('iptal edildi');
        $this->get('/transactions?type=inflow')->assertSee('Kayıt bulunamadı.');
        $this->get('/transactions?type=inflow&status=all')->assertSee('Ters kayıt');
    }

    // --- Hesaplar ---

    public function test_account_create_with_opening_balance_and_encrypted_iban(): void
    {
        $user = User::factory()->finance()->create();

        $this->actingAs($user)->post('/accounts', [
            'type' => 'bank', 'name' => 'İş Bankası Vadesiz', 'bank_name' => 'Türkiye İş Bankası',
            'iban' => 'tr33 0006 1005 1978 6457 8413 26', 'currency' => 'TRY',
            'opening_amount' => '125.000,00', 'opening_date' => '2026-01-01',
        ])->assertRedirect();

        $account = Account::where('name', 'İş Bankası Vadesiz')->sole();
        $this->assertSame(self::VALID_IBAN, $account->iban);
        $this->assertStringNotContainsString('0006', DB::table('accounts')->where('id', $account->id)->value('iban'), 'IBAN veritabanında açık metin olmamalı.');
        $this->assertSame('125000.00', Transaction::where('type', 'opening')->sole()->amount);

        $log = AuditLog::where('action', 'created')->where('auditable_type', 'Account')->sole();
        $this->assertSame('**********************1326', $log->new_values['iban']);

        $this->get('/accounts')->assertSee('TR33 **** **** 1326')->assertDontSee(self::VALID_IBAN)->assertSee('125.000,00 ₺');
    }

    public function test_invalid_iban_checksum_is_rejected(): void
    {
        $this->actingAs(User::factory()->finance()->create())->post('/accounts', [
            'type' => 'bank', 'name' => 'Hatalı', 'bank_name' => 'B', 'iban' => 'TR330006100519786457841327', 'currency' => 'TRY',
        ])->assertSessionHasErrors(['iban' => 'IBAN kontrol basamağı hatalı.']);

        $this->assertSame(0, Account::count());
    }

    public function test_pos_account_requires_settlement_bank(): void
    {
        $this->actingAs(User::factory()->finance()->create())->post('/accounts', [
            'type' => 'pos', 'name' => 'POS', 'bank_name' => 'B', 'currency' => 'TRY',
        ])->assertSessionHasErrors('settlement_account_id');
    }

    public function test_account_with_balance_cannot_be_deactivated(): void
    {
        $user = User::factory()->finance()->create();
        [$bank] = $this->seedBankWithInflow($user);

        $this->actingAs($user)->put("/accounts/{$bank->id}", [
            'name' => $bank->name, 'bank_name' => $bank->bank_name, 'is_active' => 0,
        ])->assertSessionHasErrors('is_active');

        $this->assertTrue($bank->fresh()->is_active);
    }

    public function test_menu_highlights_correct_finance_item(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get('/transactions?type=outflow')
            ->assertSee('class="nav-link active">Nakit Çıkışları', false)
            ->assertDontSee('class="nav-link active">Nakit Girişleri', false)
            ->assertDontSee('class="nav-link active">Tüm Hareketler', false);

        $this->actingAs(User::factory()->finance()->create())
            ->get('/transactions/create?type=outflow')
            ->assertSee('class="nav-link active">Nakit Çıkışları', false)
            ->assertDontSee('class="nav-link active">Tüm Hareketler', false);
    }
}
