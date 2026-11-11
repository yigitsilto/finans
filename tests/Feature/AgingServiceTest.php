<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\User;
use App\Services\AgingService;
use App\Services\ReceivableService;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_DATE = '2026-06-30';

    private AgingService $aging;

    private ReceivableService $receivables;

    private User $user;

    private Account $bank;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aging = app(AgingService::class);
        $this->receivables = app(ReceivableService::class);
        $this->user = User::factory()->finance()->create();
        $this->bank = Account::factory()->bank()->create();
        $this->customer = $this->customer('Ayşe Yılmaz');
    }

    private function customer(string $name): Customer
    {
        $c = new Customer(['name' => $name]);
        $c->created_by = $this->user->id;
        $c->save();

        return $c;
    }

    private function reportDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::REPORT_DATE);
    }

    /** Rapor tarihine göre $daysOverdue gün gecikmiş alacak. */
    private function receivable(int $daysOverdue, string $amount = '1000', ?Customer $customer = null, ?string $issue = null): Receivable
    {
        $due = $this->reportDate()->subDays($daysOverdue);

        return $this->receivables->create([
            'customer_id' => ($customer ?? $this->customer)->id, 'reference_no' => "POL-{$daysOverdue}-".uniqid(),
            'amount' => $amount, 'issue_date' => $issue ?? min($due, $this->reportDate())->subDays(5)->toDateString(),
            'due_date' => $due->toDateString(),
        ], $this->user);
    }

    private function collect(Receivable $r, string $amount, string $date): void
    {
        $this->receivables->collect($r, ['date' => $date, 'amount' => $amount, 'account_id' => $this->bank->id], $this->user);
    }

    public static function boundaryDays(): array
    {
        return [[0, 'not_due'], [30, 'd0_30'], [31, 'd31_60'], [60, 'd31_60'], [61, 'd61_90'], [90, 'd61_90'], [91, 'd90_plus']];
    }

    #[DataProvider('boundaryDays')]
    public function test_boundary_days_end_to_end(int $days, string $bucket): void
    {
        $r = $this->receivable($days);

        $line = $this->aging->lines($this->reportDate())->firstWhere('id', $r->id);

        $this->assertSame($days, $line->days, 'Gecikme = rapor tarihi − vade');
        $this->assertSame($bucket, $line->bucket);
        $this->assertSame('1000.00', $this->aging->report($this->reportDate())['buckets'][$bucket]['amount']);
    }

    public function test_only_remaining_amount_is_aged(): void
    {
        $r = $this->receivable(45, '1.000');
        $this->collect($r, '400', '2026-06-01');

        $report = $this->aging->report($this->reportDate());

        $this->assertSame('600.00', $report['buckets']['d31_60']['amount']);
        $this->assertSame('600.00', $report['total']);
    }

    public function test_fully_collected_and_cancelled_receivables_are_excluded(): void
    {
        $paid = $this->receivable(10, '500');
        $this->collect($paid, '500', '2026-06-29');
        $cancelled = $this->receivable(20, '300');
        $this->receivables->cancel($cancelled, 'Poliçe iptal', $this->user);

        // İptal bugün yapıldı; bugünkü raporda ne tahsil edilen ne iptal edilen alacak görünür.
        $report = $this->aging->report(CarbonImmutable::today());

        $this->assertSame(0, $report['count']);
        $this->assertSame('0.00', $report['total']);
        $this->assertNull($report['buckets']['d0_30']['percent'], 'Toplam sıfırken oran hesaplanmaz');
    }

    public function test_past_report_date_ignores_later_collections_and_receivables(): void
    {
        $r = $this->receivable(45, '1.000');                      // vade 2026-05-16
        $this->collect($r, '400', '2026-06-15');
        $this->collect($r, '600', '2026-07-10');                   // rapor tarihinden sonra
        $this->receivable(-10, '250', null, '2026-07-05');          // rapor tarihinden sonra oluşmuş (vade 10.07)

        $june = $this->aging->report($this->reportDate());
        $this->assertSame('600.00', $june['total'], '30 Haziran itibarıyla 600 kalmıştı');
        $this->assertSame('600.00', $june['buckets']['d31_60']['amount']);
        $this->assertSame(1, $june['count']);

        $may = $this->aging->report(CarbonImmutable::parse('2026-05-31'));
        $this->assertSame('1000.00', $may['buckets']['d0_30']['amount'], '31 Mayıs: 15 gün gecikmiş, hiç tahsilat yok');

        $today = $this->aging->report(CarbonImmutable::today());
        $this->assertSame('250.00', $today['total'], 'Bugün yalnızca yeni alacak kalıyor');
    }

    public function test_collection_reversed_after_report_date_still_counts_as_collected_on_that_date(): void
    {
        $r = $this->receivable(45, '1000');
        $this->collect($r, '1000', '2026-06-10');
        $tx = $r->collections()->first();
        app(TransactionService::class)->reverse($tx, '2026-07-01', 'Karşılıksız', $this->user);

        $this->assertSame('0.00', $this->aging->report($this->reportDate())['total']);
        $this->assertSame('1000.00', $this->aging->report(CarbonImmutable::parse('2026-07-01'))['total']);
    }

    public function test_cancelled_after_report_date_is_included_for_that_date(): void
    {
        $r = $this->receivable(10, '700');
        $this->receivables->cancel($r, 'Poliçe iptal', $this->user); // bugün (rapor tarihinden sonra) iptal

        $this->assertSame('700.00', $this->aging->report($this->reportDate())['total']);
        $this->assertSame('0.00', $this->aging->report(CarbonImmutable::today())['total']);
    }

    public function test_totals_reconcile_across_buckets_and_customers(): void
    {
        $other = $this->customer('Mehmet Öz');
        $amounts = [[-10, '100,10'], [5, '200,20'], [40, '300,30'], [75, '400,40'], [120, '500,50']];
        foreach ($amounts as [$days, $amount]) {
            $this->receivable($days, $amount);
            $this->receivable($days, $amount, $other);
        }

        $report = $this->aging->report($this->reportDate());

        $this->assertSame('3003.00', $report['total']); // 2 × (100,10 + 200,20 + 300,30 + 400,40 + 500,50)
        $bucketSum = array_reduce($report['buckets'], fn ($c, $b) => $c->plus($b['amount']), BigDecimal::zero());
        $this->assertSame($report['total'], (string) $bucketSum, 'Dilim toplamı = genel toplam');

        $customerSum = $report['customers']->reduce(fn ($c, $row) => $c->plus($row['total']), BigDecimal::zero());
        $this->assertSame($report['total'], (string) $customerSum, 'Müşteri toplamı = genel toplam');

        $percentSum = array_reduce($report['buckets'], fn ($c, $b) => $c->plus($b['percent']), BigDecimal::zero());
        $this->assertTrue($percentSum->minus(100)->abs()->isLessThanOrEqualTo('0.05'), "Yüzdeler ~100 olmalı: {$percentSum}");

        $this->assertSame('2802.80', $report['overdue']); // vadesi gelmemiş 2 × 100,10 hariç
        $this->assertSame('1001.00', $report['buckets']['d90_plus']['amount']);
    }

    public function test_customer_filter(): void
    {
        $other = $this->customer('Mehmet Öz');
        $this->receivable(10, '100');
        $this->receivable(10, '900', $other);

        $this->assertSame('900.00', $this->aging->report($this->reportDate(), $other->id)['total']);
    }

    public function test_aging_screen(): void
    {
        $this->receivable(120, '5.000');
        $this->receivable(15, '1.250,50');
        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/aging?date='.self::REPORT_DATE)
            ->assertOk()
            ->assertSee('Vadesi Gelmemiş')->assertSee('90+ Gün')
            ->assertSee('5.000,00 ₺')->assertSee('6.250,50 ₺')
            ->assertSee('Kritik tahsilat riski bulunmaktadır')
            ->assertSee('30.06.2026 tarihi itibarıyla');

        $this->get('/aging?date='.self::REPORT_DATE.'&view=detail&bucket=d0_30')
            ->assertOk()->assertSee('15 gün')->assertDontSee('120 gün');

        $this->get('/aging?date=30.06.2026')->assertSessionHasErrors('date');
    }
}
