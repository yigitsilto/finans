<?php

namespace App\Reports;

use App\Enums\TransactionType;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\TransactionController;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\User;
use App\Services\AgingService;
use App\Services\CashflowService;
use App\Services\ExpenseService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Dışa aktarılabilir raporlar. Her rapor ekrandaki servisin aynısını kullanır;
 * böylece ekran, Excel ve PDF aynı rakamları gösterir.
 */
class ReportBuilder
{
    public const REPORTS = [
        'cashflow' => 'Nakit Akış Raporu',
        'aging' => 'Alacak Yaşlandırma Raporu',
        'transactions' => 'Finansal Hareket Listesi',
        'expenses' => 'Gider Analizi',
        'audit' => 'Denetim Kayıtları',
    ];

    /**
     * Bu sayının üzerindeki hareket/denetim listeleri reddedilir (bellek koruması).
     * Ölçüm (11 sütun xlsx): 5.000 satır ≈ 77 MB / 1,3 sn · 20.000 ≈ 175 MB / 5 sn · 50.000 ≈ 379 MB / 13 sn.
     */
    public const MAX_EXPORT_ROWS = 20000;

    public function __construct(
        private readonly CashflowService $cashflow,
        private readonly AgingService $aging,
        private readonly ExpenseService $expenses,
    ) {}

    public function build(string $report, Request $request): ReportTable
    {
        return match ($report) {
            'cashflow' => $this->cashflow($request),
            'aging' => $this->aging($request),
            'transactions' => $this->transactions($request),
            'expenses' => $this->expenses($request),
            'audit' => $this->audit($request),
        };
    }

    private function range(Request $request, string $defaultFrom): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => 'Başlangıç', 'to' => 'Bitiş']);

        return [
            CarbonImmutable::parse($data['from'] ?? $defaultFrom)->startOfDay(),
            CarbonImmutable::parse($data['to'] ?? today()->toDateString())->startOfDay(),
        ];
    }

    private static function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to) ? $from->format('d.m.Y') : $from->format('d.m.Y').' – '.$to->format('d.m.Y');
    }

    private function cashflow(Request $request): ReportTable
    {
        [$from, $to] = $this->range($request, today()->startOfMonth()->toDateString());
        $r = $this->cashflow->report($from, $to);

        $t = new ReportTable('cashflow', self::REPORTS['cashflow'], self::period($from, $to));
        $t->summary('A. Başlangıç kullanılabilir nakit (kasa + banka)', $r['opening']['available'])
            ->summary('   Başlangıç blokeli POS (ayrı)', $r['opening']['pos'])
            ->summary('B. Toplam nakit girişleri', $r['total_inflow'])
            ->summary('C. Toplam nakit çıkışları', $r['total_outflow'])
            ->summary('   Kredi taksitleri – anapara', $r['loan_installments']['principal'])
            ->summary('   Kredi taksitleri – faiz', $r['loan_installments']['interest'])
            ->summary('D. Net dönem nakit akışı (B − C)', $r['net'])
            ->summary('   İşletme faaliyetleri neti', $r['operating_net'])
            ->summary('   Finansman faaliyetleri neti', $r['financing_net'])
            ->summary('− POS blokesindeki artış', (string) BigDecimal::of($r['pos_change'])->negated()->toScale(2))
            ->summary('+ Dönem içi açılış kayıtları', $r['period_openings_available'])
            ->summary('E. Dönem sonu kullanılabilir nakit', $r['closing_available'])
            ->summary('   Dönem sonu blokeli POS', $r['closing_pos'])
            ->summary('Mutabakat', $r['reconciled'] ? 'Tüm kontroller eşleşiyor' : 'MUTABAKAT HATASI', 'text');

        foreach (['inflows' => ['Nakit Girişleri', $r['total_inflow']], 'outflows' => ['Nakit Çıkışları', $r['total_outflow']]] as $key => [$title, $total]) {
            $rows = [];
            foreach ($r[$key] as $activity) {
                foreach ($activity['groups'] as $group) {
                    if ($group['items'] === []) {
                        $rows[] = [$activity['label'], $group['name'], '', $group['total']];
                    }
                    foreach ($group['items'] as $item) {
                        $rows[] = [$activity['label'], $group['name'], $item['name'], $item['total']];
                    }
                }
            }
            $t->section($title, [['Faaliyet', 'text'], ['Grup', 'text'], ['Kategori', 'text'], ['Tutar', 'money']], $rows, ['Toplam', null, null, $total]);
        }

        $t->section('Hesap Bazlı Mutabakat', [
            ['Hesap', 'text'], ['Tür', 'text'], ['Açılış', 'money'], ['Açılış kaydı', 'money'], ['Giriş', 'money'],
            ['Çıkış', 'money'], ['Transfer gelen', 'money'], ['Transfer giden', 'money'], ['Kapanış', 'money'], ['Kontrol', 'text'],
        ], $r['accounts']->map(fn ($a) => [
            $a['account']->name, $a['account']->type->label(), $a['opening'], $a['openings'], $a['inflow'], $a['outflow'],
            $a['transfer_in'], $a['transfer_out'], $a['closing'], $a['ok'] ? 'Eşleşiyor' : 'HATA',
        ])->all());

        $t->section('Mutabakat Kontrolleri', [['Kontrol', 'text'], ['Beklenen', 'text'], ['Gerçekleşen', 'text'], ['Sonuç', 'text']],
            array_map(fn ($c) => [
                $c['label'],
                is_numeric($c['expected']) ? Money::format($c['expected']) : $c['expected'],
                is_numeric($c['actual']) ? Money::format($c['actual']) : $c['actual'],
                $c['ok'] ? 'Eşleşiyor' : 'EŞLEŞMİYOR',
            ], $r['checks']));

        return $t->note('Transferler ve POS aktarımları nakit akışı değildir. POS tahsilatları bankaya geçene kadar kullanılabilir nakde dahil edilmez.');
    }

    private function aging(Request $request): ReportTable
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'customer_id' => ['nullable', 'integer']]);
        $date = CarbonImmutable::parse($data['date'] ?? today()->toDateString())->startOfDay();
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $r = $this->aging->report($date, $customerId);

        $t = new ReportTable('aging', self::REPORTS['aging'], $date->format('d.m.Y').' itibarıyla');
        $t->filter('Müşteri', $customerId ? Customer::find($customerId)?->name : null);
        foreach (AgingService::BUCKETS as $key => $meta) {
            $t->summary($meta['label'].' ('.$meta['risk'].')', $r['buckets'][$key]['amount']);
        }
        $t->summary('Toplam kalan alacak', $r['total'])->summary('Vadesi geçmiş', $r['overdue']);

        $t->section('Müşteri Bazlı', [
            ['Müşteri', 'text'], ...array_map(fn ($m) => [$m['label'], 'money'], array_values(AgingService::BUCKETS)), ['Toplam', 'money'],
        ], $r['customers']->map(fn ($c) => [$c['name'], ...array_values($c['buckets']), $c['total']])->all(),
            ['Toplam', ...array_map(fn ($b) => $b['amount'], array_values($r['buckets'])), $r['total']]);

        $t->section('Alacak Detayı', [
            ['Müşteri', 'text'], ['Poliçe / referans', 'text'], ['Sigorta şirketi', 'text'], ['Vade', 'date'],
            ['Gecikme (gün)', 'int'], ['Dilim', 'text'], ['Alacak', 'money'], ['Tahsil edilen', 'money'], ['Kalan', 'money'],
        ], $r['lines']->map(fn ($l) => [
            $l->customer_name, $l->reference_no, $l->insurance_company, $l->due_date, $l->days,
            AgingService::BUCKETS[$l->bucket]['label'], $l->amount, $l->collected, $l->remaining,
        ])->all(), ['Toplam', null, null, null, null, null, null, null, $r['total']]);

        return $t->note('Gecikme günü = rapor tarihi − vade tarihi. Yalnızca kalan tutar yaşlandırılır. Vadesi rapor tarihine eşit alacak "Vadesi Gelmemiş" dilimindedir.');
    }

    private function transactions(Request $request): ReportTable
    {
        $filters = TransactionController::validateFilters($request);
        $query = TransactionController::filteredQuery($filters);
        $this->assertSize($query->count());

        $type = isset($filters['type']) ? TransactionType::from($filters['type']) : null;
        $period = isset($filters['from']) || isset($filters['to'])
            ? ($filters['from'] ?? '…').' – '.($filters['to'] ?? '…')
            : 'Tüm kayıtlar';

        $t = new ReportTable('transactions', self::REPORTS['transactions'], $period);
        $t->filter('Tür', $type?->label())
            ->filter('Hesap', isset($filters['account_id']) ? Account::find($filters['account_id'])?->name : null)
            ->filter('Kategori', isset($filters['category_id']) ? Category::find($filters['category_id'])?->name : null)
            ->filter('Arama', $filters['q'] ?? null)
            ->filter('Kayıtlar', ($filters['status'] ?? 'effective') === 'all' ? 'İptaller dahil' : 'Geçerli kayıtlar');

        $rows = [];
        $total = BigDecimal::zero();
        $query->with(['category.parent', 'fromAccount', 'toAccount', 'partner', 'reversal'])
            ->orderBy('date')->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$rows, &$total) {
                foreach ($chunk as $tx) {
                    $total = $total->plus($tx->amount);
                    $rows[] = [
                        $tx->date->toDateString(), $tx->id, $tx->type->label(), $tx->category?->fullName(),
                        $tx->fromAccount?->name, $tx->toAccount?->name, $tx->partner?->name ?? $tx->counterparty,
                        $tx->reference_no, $tx->description,
                        $tx->isReversal() ? 'Ters kayıt' : ($tx->reversal ? 'İptal edildi' : ''),
                        (string) $tx->amount,
                    ];
                }
            });

        $t->section(null, [
            ['Tarih', 'date'], ['No', 'int'], ['Tür', 'text'], ['Kategori', 'text'], ['Kaynak hesap', 'text'], ['Hedef hesap', 'text'],
            ['İlgili', 'text'], ['Referans', 'text'], ['Açıklama', 'text'], ['Durum', 'text'], ['Tutar', 'money'],
        ], $rows, $type ? ['Toplam ('.count($rows).' kayıt)', ...array_fill(0, 9, null), (string) Money::of($total)] : null);

        if (! $type) {
            $t->note('Farklı türler (giriş, çıkış, transfer) karışık listelendiği için toplam satırı verilmez. Toplam için tür filtresi seçin.');
        }

        return $t;
    }

    private function expenses(Request $request): ReportTable
    {
        [$from, $to] = $this->range($request, today()->startOfMonth()->toDateString());
        $a = $this->expenses->analysis($from, $to);

        $t = new ReportTable('expenses', self::REPORTS['expenses'], self::period($from, $to));
        $t->summary('Toplam nakit çıkışı', $a['total_outflow'])
            ->summary('Sabit giderler', $a['fixed'])
            ->summary('Değişken giderler', $a['variable'])
            ->summary('Diğer çıkışlar (prim, kredi, ortak vb.)', $a['other'])
            ->summary('Sabit gider oranı', $a['fixed_ratio'] ?? '—', $a['fixed_ratio'] === null ? 'text' : 'percent')
            ->summary('Değişken gider oranı', $a['variable_ratio'] ?? '—', $a['variable_ratio'] === null ? 'text' : 'percent');

        $total = BigDecimal::of($a['total_outflow']);
        $t->section('Kategoriye Göre Nakit Çıkışları', [
            ['Grup', 'text'], ['Kategori', 'text'], ['Tür', 'text'], ['Tutar', 'money'], ['Pay', 'percent'],
        ], $a['by_category']->map(fn ($r) => [
            $r->group_name, $r->category_name,
            ['fixed' => 'Sabit', 'variable' => 'Değişken'][$r->cost_type] ?? ($r->activity === 'financing' ? 'Finansman' : 'Diğer'),
            $r->total,
            $total->isPositive() ? (string) BigDecimal::of($r->total)->multipliedBy(100)->dividedBy($total, 2, RoundingMode::HALF_UP) : null,
        ])->all(), ['Toplam', null, null, $a['total_outflow'], $total->isPositive() ? '100.00' : null]);

        return $t->note('Oranların paydası dönemdeki toplam nakit çıkışıdır (PRD §19).');
    }

    private function audit(Request $request): ReportTable
    {
        $filters = AuditLogController::validateFilters($request);
        $query = AuditLogController::query($filters);
        $this->assertSize($query->count());

        $period = isset($filters['from']) || isset($filters['to']) ? ($filters['from'] ?? '…').' – '.($filters['to'] ?? '…') : 'Tüm kayıtlar';
        $t = new ReportTable('audit', self::REPORTS['audit'], $period);
        $t->filter('Kullanıcı', isset($filters['user_id']) ? User::find($filters['user_id'])?->username : null)
            ->filter('İşlem', isset($filters['action']) ? AuditLogController::ACTIONS[$filters['action']] : null)
            ->filter('Kaynak', $filters['type'] ?? null)
            ->filter('Arama', $filters['q'] ?? null);

        $rows = [];
        $query->chunk(1000, function ($chunk) use (&$rows) {
            foreach ($chunk as $log) {
                $rows[] = [
                    $log->id, $log->created_at->format('d.m.Y H:i:s'), $log->username,
                    AuditLogController::ACTIONS[$log->action] ?? $log->action,
                    $log->auditable_type ? $log->auditable_type.' #'.$log->auditable_id : null,
                    trim($log->method.' '.$log->endpoint), $log->http_status, $log->ip_address,
                ];
            }
        });

        $t->section(null, [['No', 'int'], ['Tarih', 'text'], ['Kullanıcı', 'text'], ['İşlem', 'text'], ['Kaynak', 'text'], ['Endpoint', 'text'], ['HTTP', 'int'], ['IP', 'text']], $rows);

        return $t->note('Eski/yeni değer özetleri ekranda kayıt detayında görüntülenir. Parola ve token bilgileri kayda hiç yazılmaz.');
    }

    private function assertSize(int $count): void
    {
        if ($count > self::MAX_EXPORT_ROWS) {
            throw ValidationException::withMessages(['export' => 'Seçilen filtre '.number_format($count, 0, ',', '.').' kayıt içeriyor (en fazla '.number_format(self::MAX_EXPORT_ROWS, 0, ',', '.').'). Tarih aralığını daraltın.']);
        }
    }
}
