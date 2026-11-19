<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\RecurringExpense;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\LoanService;
use App\Services\ReceivableService;
use App\Services\TransactionService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo ortamı için son 3 ayın örnek verisi. Tüm kayıtlar gerçek servislerden geçer
 * (bakiye, POS, kredi, tahsilat kuralları aynen uygulanır). Yalnızca BOŞ sisteme yüklenir.
 */
class DemoData extends Command
{
    protected $signature = 'finans:demo-data {--with-users : Finans ve Görüntüleyici demo kullanıcıları da oluştur}';

    protected $description = 'Boş sisteme son 3 ayın örnek finansal verisini yükler (yalnızca demo)';

    private User $user;

    private TransactionService $tx;

    /** @var array<string, int> */
    private array $cat = [];

    public function handle(TransactionService $tx): int
    {
        if (! config('app.demo') && ! $this->confirm('APP_DEMO açık değil. Örnek veriyi yine de yüklemek istiyor musunuz?')) {
            return self::FAILURE;
        }
        if (Account::exists() || Transaction::exists()) {
            $this->error('Sistemde zaten hesap veya hareket var. Demo verisi yalnızca boş sisteme yüklenir.');

            return self::FAILURE;
        }
        $admin = User::where('role', Role::Admin)->where('is_active', true)->orderBy('id')->first();
        if (! $admin) {
            $this->error('Önce bir Admin oluşturun: php artisan finans:create-admin <kullanici>');

            return self::FAILURE;
        }

        $this->user = $admin;
        $this->tx = $tx;
        $this->cat = Category::pluck('id', 'code')->all();
        mt_srand(2026);

        DB::transaction(fn () => $this->seed());

        if ($this->option('with-users')) {
            $this->demoUsers();
        }

        $this->info('Demo verisi yüklendi: '.Transaction::count().' hareket, '.Customer::count().' müşteri.');

        return self::SUCCESS;
    }

    private function seed(): void
    {
        $today = CarbonImmutable::today();
        $start = $today->startOfMonth()->subMonths(3);

        $cash = $this->account(AccountType::Cash, 'Merkez Kasa');
        $garanti = $this->account(AccountType::Bank, 'Garanti Vadesiz', 'Garanti BBVA');
        $is = $this->account(AccountType::Bank, 'İş Bankası Ticari', 'Türkiye İş Bankası');
        $pos = $this->account(AccountType::Pos, 'Garanti POS', 'Garanti BBVA', $garanti);

        $this->move('opening', $start, '15000', to: $cash, description: 'Devir bakiyesi');
        $this->move('opening', $start, '420000', to: $garanti, description: 'Devir bakiyesi');
        $this->move('opening', $start, '95000', to: $is, description: 'Devir bakiyesi');

        // Ortaklar
        $ali = $this->partner('Ali Zafir', '50');
        $ayse = $this->partner('Ayşe Zafir', '50');
        $this->move('inflow', $start->addDays(4), '100000', to: $garanti, category: 'partner_capital', partner: $ali, description: 'Sermaye artırımı');

        // Kredi: kullandırım + vadesi gelen taksitlerin ödenmesi
        $loan = app(LoanService::class)->create([
            'name' => 'İş Bankası İşletme Kredisi', 'bank_name' => 'Türkiye İş Bankası', 'account_id' => $is->id,
            'start_date' => $start->addDays(9)->toDateString(), 'principal' => '300000', 'annual_interest_rate' => '42',
            'term_months' => 12, 'first_due_date' => $start->addMonth()->addDays(9)->toDateString(), 'record_disbursement' => true,
        ], $this->user);

        // Tekrarlayan giderler (örnek tutarlar; sistemde sabit değil)
        $expenses = [
            ['Ofis kirası', 'fx_rent', '27000', 5, $garanti, 'Bina sahibi'],
            ['Bina aidatı', 'fx_building_fee', '1700', 10, $garanti, 'Site yönetimi'],
            ['Mali müşavirlik', 'fx_financial_advisory', '6500', 15, $garanti, 'Mali müşavir'],
            ['İnternet', 'fx_internet', '1250', 12, $garanti, 'İnternet sağlayıcı'],
            ['Personel maaşları', 'fx_salaries', '85000', 28, $is, null],
            ['SGK ödemeleri', 'fx_sgk', '24000', 26, $is, 'SGK'],
            ['Acente takip sistemi', 'fx_agency_system', '3200', 20, $garanti, 'Yazılım firması'],
        ];
        $recurring = array_map(fn ($e) => $this->recurring(...$e, start: $start), $expenses);

        // Müşteriler
        $names = ['Mehmet Öztürk', 'Aslı Kaya Lojistik Ltd. Şti.', 'Burak Şahin', 'Çağla Yıldız', 'Doğan İnşaat A.Ş.',
            'Elif Güneş', 'Gökhan Ertuğrul', 'Hatice Çelik', 'İlker Aydın Gıda', 'Şule Koç'];
        $customers = array_map(fn ($n) => $this->customer($n), $names);
        $insurers = ['Anadolu Sigorta', 'Allianz Sigorta', 'Axa Sigorta', 'Türkiye Sigorta', 'HDI Sigorta', 'Sompo Sigorta'];

        // Aylık işletme hareketleri
        for ($m = $start; $m->lte($today); $m = $m->addMonth()) {
            foreach ([3, 9, 16, 23] as $day) {
                $date = $m->setDay(min($day, $m->daysInMonth));
                if ($date->gt($today)) {
                    continue;
                }
                $insurer = $insurers[array_rand($insurers)];
                $this->move('inflow', $date, $this->amount(28000, 46000), to: $garanti, category: 'commission_income',
                    counterparty: $insurer, description: 'Acente komisyonu');
                $this->move('outflow', $date->addDays(1)->min($today), $this->amount(18000, 32000), from: $garanti, category: 'premium_transfer',
                    counterparty: $insurer, description: 'Net prim transferi');
                $this->move('outflow', $date->min($today), $this->amount(250, 1800), from: $cash,
                    category: ['vx_treats', 'vx_fuel', 'vx_office_supplies', 'vx_hospitality', 'vx_cargo'][array_rand([0, 1, 2, 3, 4])], description: 'Ofis gideri');
            }

            foreach ([7, 19] as $day) {
                $date = $m->setDay($day);
                if ($date->gt($today)) {
                    continue;
                }
                $this->move('inflow', $date, $this->amount(4000, 12000), to: $pos, category: 'customer_collection',
                    counterparty: $names[array_rand($names)], description: 'Kredi kartı tahsilatı',
                    posDate: $date->addDays(2));
                $this->move('inflow', $date, $this->amount(1500, 4000), to: $cash, category: 'customer_collection',
                    counterparty: $names[array_rand($names)], description: 'Nakit tahsilat');
            }

            if (($elec = $m->setDay(18))->lte($today)) {
                $this->move('outflow', $elec, $this->amount(1800, 2600), from: $garanti, category: 'vx_electricity', description: 'Elektrik faturası');
                $this->move('outflow', $elec, $this->amount(4500, 9000), from: $garanti, category: 'vx_digital_media', description: 'Dijital reklam');
            }
            if (($virman = $m->setDay(22))->lte($today)) {
                $this->tx->create(['type' => 'transfer', 'date' => $virman->toDateString(), 'amount' => '140000',
                    'from_account_id' => $garanti->id, 'to_account_id' => $is->id, 'description' => 'Maaş ve kredi ödemeleri için virman'], $this->user);
            }
            if (($share = $m->setDay(25))->lte($today)) {
                $this->move('outflow', $share, '15000', from: $garanti, category: 'partner_payment', partner: $ali, description: 'Ortağa ödeme');
                $this->move('outflow', $share, '15000', from: $garanti, category: 'partner_payment', partner: $ayse, description: 'Ortağa ödeme');
            }

            // Vadesi gelmiş tekrarlayan giderleri öde
            foreach ($recurring as $e) {
                $due = $e->dueDateFor($m);
                if ($due->lt($today) && $e->appliesTo($m)) {
                    app(ExpenseService::class)->pay($e, $m, ['date' => $due->toDateString()], $this->user);
                }
            }

            // Vadesi gelmiş kredi taksitleri
            foreach ($loan->installments()->whereDate('due_date', '<', $today)->get() as $inst) {
                if ($inst->due_date->lte($m->endOfMonth()) && ! app(LoanService::class)->isPaid($inst)) {
                    app(LoanService::class)->payInstallment($inst, $inst->due_date->toDateString(), $is->id, $this->user);
                }
            }

            // POS: valörü gelenleri bankaya aktar
            $this->tx->settleDuePos($this->user, $m->endOfMonth()->min($today));
        }

        // Alacaklar: yaşlandırmanın her dilimine düşen örnekler
        $receivables = app(ReceivableService::class);
        $plan = [
            // [vade (bugünden gün), tutar, tahsil oranı %]
            [20, '18500', 0], [35, '7200', 0], [-5, '12400', 30], [-18, '9800', 50],
            [-27, '4300', 0], [-42, '15600', 40], [-55, '6100', 0], [-70, '11200', 20],
            [-84, '3900', 0], [-105, '8700', 0], [-130, '5400', 50], [0, '6600', 0],
        ];
        foreach ($plan as $i => [$dueOffset, $amount, $collectedPercent]) {
            $due = $today->addDays($dueOffset);
            $issue = $due->subDays(30)->min($today);
            $r = $receivables->create([
                'customer_id' => $customers[$i % count($customers)]->id,
                'reference_no' => 'POL-'.$issue->format('Ym').'-'.str_pad((string) ($i + 101), 4, '0', STR_PAD_LEFT),
                'insurance_company' => $insurers[$i % count($insurers)],
                'description' => ['Kasko', 'Trafik sigortası', 'Konut sigortası', 'İşyeri sigortası', 'Sağlık sigortası'][$i % 5].' primi',
                'amount' => $amount,
                'issue_date' => $issue->toDateString(),
                'due_date' => $due->toDateString(),
            ], $this->user);

            if ($collectedPercent > 0) {
                $part = (string) Money::of(BigDecimal::of($amount)->multipliedBy($collectedPercent)->dividedBy(100, 2));
                $receivables->collect($r, [
                    'date' => $issue->addDays(10)->min($today)->toDateString(),
                    'amount' => $part,
                    'account_id' => $garanti->id,
                ], $this->user);
            }
        }
    }

    private function account(AccountType $type, string $name, ?string $bank = null, ?Account $settlement = null): Account
    {
        $a = new Account(['name' => $name, 'bank_name' => $bank, 'currency' => 'TRY', 'settlement_account_id' => $settlement?->id]);
        $a->type = $type;
        $a->created_by = $this->user->id;
        $a->save();

        return $a;
    }

    private function partner(string $name, string $share): Partner
    {
        $p = new Partner(['name' => $name, 'share_percent' => $share]);
        $p->created_by = $this->user->id;
        $p->save();

        return $p;
    }

    private function customer(string $name): Customer
    {
        $c = new Customer(['name' => $name, 'type' => Str::contains($name, ['Ltd', 'A.Ş', 'Gıda']) ? 'corporate' : 'individual']);
        $c->created_by = $this->user->id;
        $c->save();

        return $c;
    }

    private function recurring(string $name, string $code, string $amount, int $day, Account $account, ?string $counterparty, CarbonImmutable $start): RecurringExpense
    {
        $e = new RecurringExpense([
            'name' => $name, 'category_id' => $this->cat[$code], 'amount' => $amount, 'day_of_month' => $day,
            'start_date' => $start, 'account_id' => $account->id, 'counterparty' => $counterparty,
        ]);
        $e->created_by = $this->user->id;
        $e->save();

        return $e;
    }

    private function move(string $type, CarbonImmutable $date, string $amount, ?Account $to = null, ?Account $from = null,
        ?string $category = null, ?Partner $partner = null, ?string $counterparty = null, ?string $description = null,
        ?CarbonImmutable $posDate = null): void
    {
        $this->tx->create(array_filter([
            'type' => $type, 'date' => $date->toDateString(), 'amount' => $amount,
            'to_account_id' => $to?->id, 'from_account_id' => $from?->id,
            'category_id' => $category ? $this->cat[$category] : null, 'partner_id' => $partner?->id,
            'counterparty' => $counterparty, 'description' => $description,
            'pos_settlement_date' => $posDate?->toDateString(),
        ], fn ($v) => $v !== null), $this->user);
    }

    /** Kuruşlu, yüze yuvarlanmış örnek tutar. */
    private function amount(int $min, int $max): string
    {
        return (string) (intdiv(mt_rand($min, $max), 10) * 10).'.'.str_pad((string) (mt_rand(0, 9) * 10), 2, '0', STR_PAD_LEFT);
    }

    private function demoUsers(): void
    {
        $rows = [];
        foreach ([['demo.finans', 'Demo Finans', Role::Finance], ['demo.izleyici', 'Demo İzleyici', Role::Viewer]] as [$username, $name, $role]) {
            if (User::where('username', $username)->exists()) {
                $this->warn("{$username} zaten var, atlandı.");

                continue;
            }
            $password = Str::password(14, symbols: false);
            $u = new User(['name' => $name, 'username' => $username, 'password' => $password]);
            $u->role = $role;
            $u->is_active = true;
            $u->save();
            $rows[] = [$username, $role->label(), $password];
        }
        if ($rows) {
            $this->warn('Parolalar yalnızca şimdi gösteriliyor; güvenli bir yere kaydedin:');
            $this->table(['Kullanıcı adı', 'Rol', 'Parola'], $rows);
        }
    }
}
