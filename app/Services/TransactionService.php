<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finansal hareketlerin tek giriş noktası. Tüm iş kuralları burada uygulanır;
 * controller'lar yalnızca girdi biçimini doğrular.
 */
class TransactionService
{
    /** Oluşturulduktan sonra değiştirilebilen alanlar. Tutar/tarih/hesap değişikliği = iptal + yeni kayıt. */
    public const EDITABLE = ['category_id', 'counterparty', 'reference_no', 'description'];

    private const AUDIT_FIELDS = ['type', 'date', 'amount', 'currency', 'from_account_id', 'to_account_id', 'category_id',
        'counterparty', 'reference_no', 'description', 'pos_settlement_date', 'settles_transaction_id', 'reversal_of_id',
        'partner_id', 'loan_id', 'loan_installment_id', 'recurring_expense_id', 'expense_period', 'receivable_id'];

    /** Yalnızca sistem servislerinin (LoanService, ExpenseService) verebileceği bağlantılar. */
    private const INTERNAL_LINKS = ['loan_id', 'loan_installment_id', 'recurring_expense_id', 'expense_period', 'receivable_id'];

    public function __construct(
        private readonly BalanceService $balances,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{type: string, date: string, amount: string, from_account_id?: int|null, to_account_id?: int|null,
     *     category_id?: int|null, counterparty?: string|null, reference_no?: string|null, description?: string|null,
     *     pos_settlement_date?: string|null, partner_id?: int|null}  $data
     * @param  array<string, mixed>  $links  Sistem bağlantıları (kredi/taksit/gider dönemi); kullanıcı girdisinden alınmaz.
     */
    public function create(array $data, User $user, array $links = []): Transaction
    {
        $links = array_intersect_key($links, array_flip(self::INTERNAL_LINKS));
        $type = TransactionType::from($data['type']);
        $date = CarbonImmutable::parse($data['date'])->startOfDay();
        $amount = $this->parseAmount($data['amount']);

        $this->assertNotFuture($date);

        return DB::transaction(function () use ($data, $type, $date, $amount, $user, $links) {
            [$from, $to] = $this->lockAccounts($data['from_account_id'] ?? null, $data['to_account_id'] ?? null);

            $this->assertAccountsForType($type, $from, $to);
            $category = $this->resolveCategory($type, $data['category_id'] ?? null);
            $partnerId = $this->resolvePartnerLink($category, $data['partner_id'] ?? null);
            $this->assertLoanLink($category, $links);
            $posDate = $this->resolvePosSettlementDate($type, $to, $date, $data['pos_settlement_date'] ?? null);

            if ($type === TransactionType::Opening) {
                $this->assertSingleOpening($to);
            }

            $transaction = Transaction::create([
                'type' => $type,
                'date' => $date,
                'amount' => $amount,
                'currency' => ($from ?? $to)->currency,
                'from_account_id' => $from?->id,
                'to_account_id' => $to?->id,
                'category_id' => $category?->id,
                'counterparty' => $data['counterparty'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'description' => $data['description'] ?? null,
                'pos_settlement_date' => $posDate,
                'partner_id' => $partnerId,
                'loan_id' => $links['loan_id'] ?? null,
                'loan_installment_id' => $links['loan_installment_id'] ?? null,
                'recurring_expense_id' => $links['recurring_expense_id'] ?? null,
                'expense_period' => $links['expense_period'] ?? null,
                'receivable_id' => $links['receivable_id'] ?? null,
                'created_by' => $user->id,
            ]);

            if ($from) {
                $this->assertNotNegative($from, $date, 'amount');
            }

            $this->audit->log(AuditService::CREATED, $transaction, new: $transaction->only(self::AUDIT_FIELDS), user: $user);

            return $transaction;
        });
    }

    /**
     * Yalnızca açıklayıcı alanlar ve aynı yöndeki kategori değiştirilebilir.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Transaction $transaction, array $data, User $user): Transaction
    {
        if ($transaction->isReversal() || $transaction->isReversed()) {
            throw ValidationException::withMessages(['transaction' => 'İptal edilmiş veya ters kayıt olan hareket düzenlenemez.']);
        }

        return DB::transaction(function () use ($transaction, $data, $user) {
            $changes = array_intersect_key($data, array_flip(self::EDITABLE));

            if (array_key_exists('category_id', $changes)) {
                $newCategory = $this->resolveCategory($transaction->type, $changes['category_id']);
                $linked = $transaction->partner_id || $transaction->loan_id || $transaction->recurring_expense_id || $transaction->receivable_id;
                if ($newCategory->id !== $transaction->category_id && ($linked || $newCategory->link_type !== null)) {
                    throw ValidationException::withMessages(['category_id' => 'Ortak, kredi veya tekrarlayan gidere bağlı hareketlerin kategorisi değiştirilemez; iptal edip yeniden girin.']);
                }
                $changes['category_id'] = $newCategory->id;
            }

            $original = $transaction->getOriginal();
            $transaction->fill($changes);
            $transaction->updated_by = $user->id;
            $transaction->save();

            [$old, $new] = AuditService::diff($original, $transaction);
            unset($old['updated_by'], $new['updated_by']);
            if ($new !== []) {
                $this->audit->log(AuditService::UPDATED, $transaction, $old, $new, user: $user);
            }

            return $transaction;
        });
    }

    /**
     * Hareketi negatif tutarlı ters kayıtla iptal eder. Orijinal kayıt değişmez.
     */
    public function reverse(Transaction $transaction, string $date, string $reason, User $user, bool $allowLinked = false): Transaction
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $this->assertNotFuture($date);

        if (! $allowLinked && $transaction->loan_id) {
            throw ValidationException::withMessages(['transaction' => 'Kredi hareketleri yalnızca Krediler ekranından iptal edilebilir.']);
        }

        return DB::transaction(function () use ($transaction, $date, $reason, $user) {
            /** @var Transaction $original */
            $original = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            [$from, $to] = $this->lockAccounts($original->from_account_id, $original->to_account_id);

            if ($original->isReversal()) {
                throw ValidationException::withMessages(['transaction' => 'Ters kayıt tekrar iptal edilemez.']);
            }
            if ($original->reversal()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Bu hareket zaten iptal edilmiş.']);
            }
            if ($date->lt($original->date)) {
                throw ValidationException::withMessages(['date' => 'İptal tarihi hareket tarihinden önce olamaz.']);
            }
            if ($original->activeSettlement()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Bu POS girişi bankaya aktarılmış. Önce aktarım transferini iptal edin.']);
            }

            $reversal = Transaction::create([
                'type' => $original->type,
                'date' => $date,
                'amount' => (string) Money::of($original->amount)->negated(),
                'currency' => $original->currency,
                'from_account_id' => $original->from_account_id,
                'to_account_id' => $original->to_account_id,
                'category_id' => $original->category_id,
                'counterparty' => $original->counterparty,
                'reference_no' => $original->reference_no,
                'description' => "İptal (#{$original->id}): {$reason}",
                // Bağlantılar kopyalanır: ortak/kredi/gider toplamları SUM(amount) ile doğru kalır.
                'partner_id' => $original->partner_id,
                'loan_id' => $original->loan_id,
                'loan_installment_id' => $original->loan_installment_id,
                'recurring_expense_id' => $original->recurring_expense_id,
                'expense_period' => $original->expense_period,
                'receivable_id' => $original->receivable_id,
                'reversal_of_id' => $original->id,
                'created_by' => $user->id,
            ]);

            // İptal, hareketin giriş yaptığı hesaptan tutarı geri düşer.
            if ($to) {
                $this->assertNotNegative($to, $date, 'transaction');
            }

            $this->audit->log(AuditService::REVERSED, $original, new: ['reversal_id' => $reversal->id, 'date' => $date->toDateString(), 'reason' => $reason], user: $user);
            $this->audit->log(AuditService::CREATED, $reversal, new: $reversal->only(self::AUDIT_FIELDS), user: $user);

            return $reversal;
        });
    }

    /**
     * POS girişini bağlı banka hesabına aktarır. Para POS'tan çıkar, bankaya girer;
     * nakit girişi olarak ikinci kez sayılmaz. Her POS girişi yalnızca bir kez aktarılabilir.
     */
    public function settlePos(Transaction $posInflow, ?string $date, User $user): Transaction
    {
        return DB::transaction(function () use ($posInflow, $date, $user) {
            /** @var Transaction $inflow */
            $inflow = Transaction::whereKey($posInflow->id)->lockForUpdate()->firstOrFail();
            $pos = Account::whereKey($inflow->to_account_id)->lockForUpdate()->first();

            if ($inflow->type !== TransactionType::Inflow || $pos?->type !== AccountType::Pos) {
                throw ValidationException::withMessages(['transaction' => 'Yalnızca POS hesabına yapılan girişler aktarılabilir.']);
            }
            if ($inflow->isReversal() || $inflow->reversal()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'İptal edilmiş POS girişi aktarılamaz.']);
            }
            if ($inflow->activeSettlement()->exists()) {
                throw ValidationException::withMessages(['transaction' => 'Bu POS girişi zaten bankaya aktarılmış.']);
            }

            $bank = $pos->settlement_account_id ? Account::whereKey($pos->settlement_account_id)->lockForUpdate()->first() : null;
            if (! $bank || ! $bank->is_active || $bank->type !== AccountType::Bank) {
                throw ValidationException::withMessages(['transaction' => 'POS hesabına bağlı aktif bir banka hesabı tanımlı değil.']);
            }

            $date = CarbonImmutable::parse($date ?? $inflow->pos_settlement_date ?? today())->startOfDay();
            $this->assertNotFuture($date);
            if ($date->lt($inflow->date)) {
                throw ValidationException::withMessages(['date' => 'Aktarım tarihi POS tahsilat tarihinden önce olamaz.']);
            }

            $transfer = Transaction::create([
                'type' => TransactionType::Transfer,
                'date' => $date,
                'amount' => $inflow->amount,
                'currency' => $pos->currency,
                'from_account_id' => $pos->id,
                'to_account_id' => $bank->id,
                'reference_no' => $inflow->reference_no,
                'description' => "POS aktarımı (#{$inflow->id})",
                'settles_transaction_id' => $inflow->id,
                'created_by' => $user->id,
            ]);

            $this->assertNotNegative($pos, $date, 'transaction');
            $this->audit->log(AuditService::CREATED, $transfer, new: $transfer->only(self::AUDIT_FIELDS), user: $user);

            return $transfer;
        });
    }

    /**
     * Valör tarihi gelmiş tüm bekleyen POS girişlerini aktarır.
     *
     * @return Collection<int, Transaction> oluşturulan transferler
     */
    public function settleDuePos(User $user, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::today();

        return $this->pendingPosInflows()
            ->whereNotNull('pos_settlement_date')
            ->whereDate('pos_settlement_date', '<=', $asOf)
            ->orderBy('pos_settlement_date')->orderBy('id')
            ->get()
            ->map(fn (Transaction $inflow) => $this->settlePos($inflow, $inflow->pos_settlement_date->toDateString(), $user));
    }

    /** Bankaya henüz aktarılmamış, iptal edilmemiş POS girişleri. */
    public function pendingPosInflows()
    {
        // Not: kendine referanslı ilişkilerde iç içe whereDoesntHave yanlış tablo takma adına bağlanır;
        // bu yüzden "iptal edilmemiş aktarım yok" koşulu açık SQL ile yazıldı.
        return Transaction::query()
            ->where('type', TransactionType::Inflow)
            ->whereHas('toAccount', fn ($q) => $q->where('type', AccountType::Pos))
            ->effective()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('transactions as s')
                ->whereColumn('s.settles_transaction_id', 'transactions.id')
                ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('transactions as r')->whereColumn('r.reversal_of_id', 's.id')));
    }

    private function parseAmount(string $amount): string
    {
        try {
            $value = Money::parse($amount);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        if (! BigDecimal::of($value)->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Tutar sıfırdan büyük olmalıdır.']);
        }

        return $value;
    }

    private function assertNotFuture(CarbonImmutable $date): void
    {
        if ($date->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => 'Gerçekleşmiş hareketler ileri tarihli girilemez.']);
        }
    }

    /**
     * Hesapları kilitleyerek okur (eşzamanlı yazmalarda bakiye kontrolü tutarlı kalsın).
     *
     * @return array{0: ?Account, 1: ?Account}
     */
    private function lockAccounts(?int $fromId, ?int $toId): array
    {
        $ids = array_values(array_filter([$fromId, $toId]));
        $accounts = Account::whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$fromId ? $accounts->get($fromId) : null, $toId ? $accounts->get($toId) : null];
    }

    private function assertAccountsForType(TransactionType $type, ?Account $from, ?Account $to): void
    {
        $needsFrom = in_array($type, [TransactionType::Outflow, TransactionType::Transfer], true);
        $needsTo = $type !== TransactionType::Outflow;

        if ($needsFrom && ! $from) {
            throw ValidationException::withMessages(['from_account_id' => 'Kaynak hesap seçilmelidir.']);
        }
        if ($needsTo && ! $to) {
            throw ValidationException::withMessages(['to_account_id' => 'Hedef hesap seçilmelidir.']);
        }
        if (! $needsFrom && $from) {
            throw ValidationException::withMessages(['from_account_id' => 'Bu işlem türünde kaynak hesap olmaz.']);
        }
        if (! $needsTo && $to) {
            throw ValidationException::withMessages(['to_account_id' => 'Bu işlem türünde hedef hesap olmaz.']);
        }

        foreach (['from_account_id' => $from, 'to_account_id' => $to] as $field => $account) {
            if ($account && ! $account->is_active) {
                throw ValidationException::withMessages([$field => 'Pasif hesaba hareket girilemez.']);
            }
        }

        if ($from && $to) {
            if ($from->is($to)) {
                throw ValidationException::withMessages(['to_account_id' => 'Kaynak ve hedef hesap aynı olamaz.']);
            }
            if ($from->currency !== $to->currency) {
                throw ValidationException::withMessages(['to_account_id' => 'Farklı para birimli hesaplar arasında transfer yapılamaz.']);
            }
            // POS'a para transferle girmez; POS'a yalnızca tahsilat (giriş) yazılır.
            if ($to->type === AccountType::Pos) {
                throw ValidationException::withMessages(['to_account_id' => 'POS hesabına transfer yapılamaz.']);
            }
        }

        if ($type === TransactionType::Outflow && $from->type === AccountType::Pos) {
            throw ValidationException::withMessages(['from_account_id' => 'Blokeli POS bakiyesinden doğrudan ödeme yapılamaz.']);
        }
    }

    private function resolveCategory(TransactionType $type, mixed $categoryId): ?Category
    {
        $direction = match ($type) {
            TransactionType::Inflow => 'inflow',
            TransactionType::Outflow => 'outflow',
            default => null,
        };

        if ($direction === null) {
            if ($categoryId) {
                throw ValidationException::withMessages(['category_id' => 'Bu işlem türünde kategori seçilmez.']);
            }

            return null;
        }

        $category = $categoryId ? Category::selectable($direction)->find($categoryId) : null;
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Geçerli bir '.($direction === 'inflow' ? 'giriş' : 'çıkış').' kategorisi seçilmelidir.']);
        }

        return $category;
    }

    private function resolvePartnerLink(?Category $category, mixed $partnerId): ?int
    {
        if ($category?->link_type !== 'partner') {
            if ($partnerId) {
                throw ValidationException::withMessages(['partner_id' => 'Ortak yalnızca ortak kategorilerindeki hareketlerde seçilir.']);
            }

            return null;
        }

        $partner = $partnerId ? Partner::active()->find($partnerId) : null;
        if (! $partner) {
            throw ValidationException::withMessages(['partner_id' => 'Bu kategori için aktif bir ortak seçilmelidir.']);
        }

        return $partner->id;
    }

    /** @param array<string, mixed> $links */
    private function assertLoanLink(?Category $category, array $links): void
    {
        $isLoanCategory = $category?->link_type === 'loan';

        if ($isLoanCategory && empty($links['loan_id'])) {
            throw ValidationException::withMessages(['category_id' => 'Kredi kullanımı ve taksit ödemeleri Krediler ekranından yapılır.']);
        }
        if (! $isLoanCategory && ! empty($links['loan_id'])) {
            throw ValidationException::withMessages(['category_id' => 'Krediye bağlı hareket kredi kategorisinde olmalıdır.']);
        }
    }

    private function resolvePosSettlementDate(TransactionType $type, ?Account $to, CarbonImmutable $date, ?string $posDate): ?CarbonImmutable
    {
        $isPosInflow = $type === TransactionType::Inflow && $to?->type === AccountType::Pos;

        if (! $isPosInflow) {
            if ($posDate) {
                throw ValidationException::withMessages(['pos_settlement_date' => 'Valör tarihi yalnızca POS tahsilatlarında girilir.']);
            }

            return null;
        }

        if (! $posDate) {
            throw ValidationException::withMessages(['pos_settlement_date' => 'POS tahsilatı için bankaya geçiş (valör) tarihi zorunludur.']);
        }

        $posDate = CarbonImmutable::parse($posDate)->startOfDay();
        if ($posDate->lt($date)) {
            throw ValidationException::withMessages(['pos_settlement_date' => 'Valör tarihi tahsilat tarihinden önce olamaz.']);
        }

        return $posDate;
    }

    private function assertSingleOpening(Account $account): void
    {
        $exists = Transaction::where('type', TransactionType::Opening)->where('to_account_id', $account->id)->effective()->exists();

        if ($exists) {
            throw ValidationException::withMessages(['to_account_id' => 'Bu hesabın açılış bakiyesi zaten girilmiş. Düzeltmek için önce mevcut açılışı iptal edin.']);
        }
    }

    private function assertNotNegative(Account $account, CarbonImmutable $from, string $field): void
    {
        if ($account->allow_negative) {
            return;
        }

        $minimum = $this->balances->minimumBalanceFrom($account, $from);
        if (BigDecimal::of($minimum)->isNegative()) {
            throw ValidationException::withMessages([
                $field => "Bu işlem {$account->name} bakiyesini eksiye düşürür (en düşük bakiye: ".Money::format($minimum).').',
            ]);
        }
    }
}
