<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\User;
use App\Reports\PdfRenderer;
use App\Reports\ReportTable;
use App\Services\ReceivableService;
use App\Services\TransactionService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExportAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TURKISH = 'Ğ Ü Ş İ Ö Ç ğ ü ş ı ö ç – Çağrı Şenyürek İşletmesi';

    private User $finance;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00'));
        $this->finance = User::factory()->finance()->create(['name' => 'Ayşe Çelik']);
        $this->bank = Account::factory()->bank()->create(['name' => 'Garanti Vadesiz']);

        $tx = app(TransactionService::class);
        $tx->create(['type' => 'opening', 'date' => '2026-10-01', 'amount' => '100.000', 'to_account_id' => $this->bank->id], $this->finance);
        $tx->create(['type' => 'inflow', 'date' => '2026-11-03', 'amount' => '12.345,67', 'to_account_id' => $this->bank->id,
            'category_id' => $this->cat('commission_income'), 'counterparty' => 'Anadolu Sigorta', 'description' => self::TURKISH], $this->finance);
        $tx->create(['type' => 'outflow', 'date' => '2026-11-05', 'amount' => '27.000', 'from_account_id' => $this->bank->id,
            'category_id' => $this->cat('fx_rent'), 'description' => 'Kasım kirası'], $this->finance);
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function sheet(TestResponse $response): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xt').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: $response->getFile()->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(Worksheet $sheet): array
    {
        return $sheet->toArray(null, false, false, false);
    }

    private function findRow(array $rows, string $firstCell): ?array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $firstCell) {
                return $row;
            }
        }

        return null;
    }

    // --- Excel ---

    public function test_transactions_excel_keeps_turkish_text_numbers_and_totals(): void
    {
        $response = $this->actingAs($this->finance)->get('/reports/exports/transactions.xlsx?type=inflow&from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->sheet($response);
        $rows = $this->rows($sheet);

        $this->assertSame('Zafir Sigorta Aracılık Hizmetleri', $rows[0][0]);
        $this->assertSame('Finansal Hareket Listesi', $rows[1][0]);
        $this->assertSame('Dönem: 2026-11-01 – 2026-11-30', $rows[2][0]);
        $this->assertStringContainsString('Ayşe Çelik', $rows[3][0]);
        $this->assertSame('Nakit girişi', $this->findRow($rows, 'Tür')[1], 'Filtreler dosyada yazılı');

        $flat = array_merge(...$rows);
        $this->assertContains(self::TURKISH, $flat, 'Türkçe karakterler bozulmadan yazılmalı');

        // Tutar sayısal hücre + TL biçimi. (PhpSpreadsheet hücre nesnesini döngüde yeniden kullandığı
        // için koordinat saklanır, nesne değil.)
        $coordinates = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === 12345.67) {
                    $coordinates[] = $cell->getCoordinate();
                }
            }
        }
        $this->assertCount(2, $coordinates, 'Tutar satırı ve toplam satırı sayısal olmalı');
        foreach ($coordinates as $coordinate) {
            $this->assertStringContainsString('₺', $sheet->getStyle($coordinate)->getNumberFormat()->getFormatCode(), $coordinate);
        }
        $this->assertSame('12.345,67 ₺', str_replace(["\u{00A0}", ' '], ['', ' '], Money::format('12345.67')));

        $total = $this->findRow($rows, 'Toplam (1 kayıt)');
        $this->assertEqualsWithDelta(12345.67, end($total), 0.001);
    }

    public function test_cashflow_excel_contains_a_to_e_and_reconciliation(): void
    {
        $rows = $this->rows($this->sheet(
            $this->actingAs(User::factory()->viewer()->create())->get('/reports/exports/cashflow.xlsx?from=2026-11-01&to=2026-11-30')->assertOk()
        ));

        $this->assertEqualsWithDelta(100000.00, $this->findRow($rows, 'A. Başlangıç kullanılabilir nakit (kasa + banka)')[1], 0.001);
        $this->assertEqualsWithDelta(12345.67, $this->findRow($rows, 'B. Toplam nakit girişleri')[1], 0.001);
        $this->assertEqualsWithDelta(27000.00, $this->findRow($rows, 'C. Toplam nakit çıkışları')[1], 0.001);
        $this->assertEqualsWithDelta(-14654.33, $this->findRow($rows, 'D. Net dönem nakit akışı (B − C)')[1], 0.001);
        $this->assertEqualsWithDelta(85345.67, $this->findRow($rows, 'E. Dönem sonu kullanılabilir nakit')[1], 0.001);
        $this->assertSame('Tüm kontroller eşleşiyor', $this->findRow($rows, 'Mutabakat')[1]);
        $this->assertNotNull($this->findRow($rows, 'Hesap Bazlı Mutabakat'));
    }

    public function test_aging_and_expense_excel(): void
    {
        $c = new Customer(['name' => 'Şükrü Öğütçü']);
        $c->created_by = $this->finance->id;
        $c->save();
        app(ReceivableService::class)->create(['customer_id' => $c->id, 'reference_no' => 'KSK-Ğ1', 'amount' => '4.000',
            'issue_date' => '2026-07-01', 'due_date' => '2026-07-15'], $this->finance);
        $this->actingAs($this->finance);

        $aging = $this->rows($this->sheet($this->get('/reports/exports/aging.xlsx?date=2026-11-11')->assertOk()));
        $this->assertEqualsWithDelta(4000, $this->findRow($aging, '90+ Gün (Kritik risk)')[1], 0.001);
        $this->assertNotNull($this->findRow($aging, 'Şükrü Öğütçü'));

        $expenses = $this->rows($this->sheet($this->get('/reports/exports/expenses.xlsx?from=2026-11-01&to=2026-11-30')->assertOk()));
        $this->assertEqualsWithDelta(100.00, $this->findRow($expenses, 'Sabit gider oranı')[1], 0.001);
    }

    // --- PDF ---

    public function test_pdf_is_generated_with_embedded_dejavu_font(): void
    {
        $response = $this->actingAs($this->finance)->get('/reports/exports/transactions.pdf?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf, 'Türkçe karakterler için DejaVu Sans gömülmeli');
        $this->assertMatchesRegularExpression('/attachment; filename="zafir-transactions-\d{8}-\d{6}\.pdf"/', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_template_renders_header_and_turkish_text(): void
    {
        $t = (new ReportTable('x', 'Nakit Akış Raporu', '01.11.2026 – 30.11.2026'))
            ->filter('Hesap', 'Garanti Vadesiz')
            ->summary('Net', '-14654.33')
            ->section('Bölüm', [['Açıklama', 'text'], ['Tutar', 'money']], [[self::TURKISH, '1234.5']], ['Toplam', '1234.50']);

        $html = view('exports.pdf', ['report' => $t, 'generatedBy' => 'Ayşe Çelik', 'truncated' => false, 'maxRows' => 2000])->render();

        $this->assertStringContainsString('Zafir Sigorta Aracılık Hizmetleri', $html);
        $this->assertStringContainsString('Nakit Akış Raporu', $html);
        $this->assertStringContainsString('Dönem: 01.11.2026 – 30.11.2026', $html);
        $this->assertStringContainsString('Oluşturulma: 11.11.2026', $html);
        $this->assertStringContainsString(e(self::TURKISH), $html);
        $this->assertStringContainsString('-14.654,33 ₺', $html);
        $this->assertStringContainsString('font-family: "DejaVu Sans"', $html);
    }

    public function test_large_pdf_is_truncated_with_notice(): void
    {
        $rows = array_map(fn ($i) => ["Satır {$i} ığüşöç", '10.00'], range(1, PdfRenderer::MAX_ROWS + 500));
        $t = (new ReportTable('x', 'Büyük', 'test'))->section(null, [['Açıklama', 'text'], ['Tutar', 'money']], $rows);

        $start = microtime(true);
        $pdf = app(PdfRenderer::class)->render($t, 'test');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame('512M', ini_get('memory_limit'), 'Bellek sınırı PDF sonrası eski haline dönmeli');
        $this->assertLessThan(60, microtime(true) - $start, 'Büyük PDF makul sürede üretilmeli');
    }

    // --- Büyük veri ---

    public function test_large_transaction_export(): void
    {
        $now = now();
        $cat = $this->cat('commission_income');
        foreach (array_chunk(range(1, 5000), 1000) as $chunk) {
            DB::table('transactions')->insert(array_map(fn ($i) => [
                'type' => 'inflow', 'date' => '2026-10-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'amount' => '1.01', 'to_account_id' => $this->bank->id, 'category_id' => $cat,
                'description' => "Toplu işlem {$i} ğüşıöç", 'created_by' => $this->finance->id,
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }

        $start = microtime(true);
        $response = $this->actingAs($this->finance)->get('/reports/exports/transactions.xlsx?type=inflow&from=2026-10-01&to=2026-10-31')->assertOk();
        $elapsed = microtime(true) - $start;

        $rows = $this->rows($this->sheet($response));
        $this->assertNotNull($total = $this->findRow($rows, 'Toplam (5000 kayıt)'));
        $this->assertEqualsWithDelta(5050.00, end($total), 0.001, '5000 × 1,01');
        $this->assertLessThan(60, $elapsed, "5000 satırlık Excel {$elapsed} sn sürdü");
        $this->assertSame(5000, AuditLog::where('action', 'exported')->latest('id')->first()->new_values['rows']);
    }

    // --- Yetki ve denetim ---

    public static function financialReports(): array
    {
        return [['cashflow'], ['aging'], ['transactions'], ['expenses']];
    }

    #[DataProvider('financialReports')]
    public function test_viewer_can_download_financial_reports(string $report): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get("/reports/exports/{$report}.xlsx")->assertOk();
        $this->get("/reports/exports/{$report}.pdf")->assertOk();
    }

    public function test_unauthorized_exports_are_blocked(): void
    {
        $this->get('/reports/exports/cashflow.xlsx')->assertRedirect('/login');

        $this->actingAs(User::factory()->viewer()->create())->get('/reports/exports/audit.xlsx')->assertForbidden();
        $this->actingAs($this->finance)->get('/reports/exports/audit.pdf')->assertForbidden();
        $this->actingAs($this->finance)->get('/reports/exports/users.xlsx')->assertNotFound();
        $this->actingAs($this->finance)->get('/reports/exports/cashflow.csv')->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get('/reports/exports/audit.xlsx')->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'exported')->count(), 'Yalnızca başarılı indirme kaydedilir');
    }

    public function test_every_download_is_audited_without_secrets(): void
    {
        $this->actingAs($this->finance)->get('/reports/exports/cashflow.pdf?from=2026-11-01&to=2026-11-30')->assertOk();

        $log = AuditLog::where('action', 'exported')->sole();
        $this->assertSame($this->finance->id, $log->user_id);
        $this->assertSame('/reports/exports/cashflow.pdf', $log->endpoint);
        // jsonb anahtar sırasını korumaz; sırasız karşılaştırılır.
        $this->assertSame('cashflow', $log->new_values['report']);
        $this->assertSame('pdf', $log->new_values['format']);
        $this->assertEqualsCanonicalizing(['from' => '2026-11-01', 'to' => '2026-11-30'], $log->new_values['filters']);
    }

    public function test_audit_screen_is_admin_only_and_filterable(): void
    {
        $this->actingAs(User::factory()->viewer()->create())->get('/audit-logs')->assertForbidden();
        $this->actingAs($this->finance)->get('/audit-logs')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->post('/logout');
        $this->post('/login', ['username' => $this->finance->username, 'password' => 'yanlis-parola-1']);
        $this->actingAs($admin);

        $this->get('/audit-logs')->assertOk()->assertSee('Başarısız giriş')->assertSee('Oluşturma');
        $this->get('/audit-logs?action=login_failed')->assertOk()->assertSee($this->finance->username)->assertDontSee('Oluşturma</td>', false);

        $log = AuditLog::where('auditable_type', 'Transaction')->where('action', 'created')->get()
            ->first(fn ($l) => ($l->new_values['amount'] ?? null) === '12345.67');
        $this->get("/audit-logs/{$log->id}")->assertOk()->assertSee('Yeni değerler')->assertSee('12345.67');
        $this->get('/audit-logs?from=2026-12-01&to=2026-11-01')->assertSessionHasErrors('to');
    }

    public function test_audit_detail_never_shows_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/users', [
            'name' => 'Yeni', 'username' => 'yeni', 'role' => 'viewer',
            'password' => 'Gizli-Parola-123', 'password_confirmation' => 'Gizli-Parola-123',
        ]);
        $this->post('/accounts', ['type' => 'bank', 'name' => 'İş', 'bank_name' => 'İş', 'iban' => 'TR330006100519786457841326', 'currency' => 'TRY']);

        foreach (AuditLog::all() as $log) {
            $this->get("/audit-logs/{$log->id}")->assertOk()
                ->assertDontSee('Gizli-Parola-123')->assertDontSee('TR330006100519786457841326');
        }
        $this->get('/reports/exports/audit.xlsx')->assertOk();
    }
}
