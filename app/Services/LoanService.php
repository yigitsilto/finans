<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kredi yaşam döngüsü: plan oluşturma, kullandırım (nakit girişi), taksit ödemesi.
 * Her taksit ödemesi anapara ve faiz için İKİ AYRI nakit çıkışı olarak yazılır (finansman faaliyeti).
 */
class LoanService
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly LoanScheduleCalculator $calculator,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{name: string, bank_name: string, account_id: int, start_date: string, principal: string,
     *     annual_interest_rate: string, term_months: int, first_due_date: string, notes?: string|null,
     *     record_disbursement?: bool}  $data
     */
    public function create(array $data, User $user): Loan
    {
        try {
            $principal = Money::parse($data['principal']);
            $rate = BigDecimal::of(str_replace(',', '.', (string) $data['annual_interest_rate']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['principal' => 'Kredi tutarı veya faiz oranı geçersiz.']);
        }
        if (! BigDecimal::of($principal)->isPositive()) {
            throw ValidationException::withMessages(['principal' => 'Kredi tutarı sıfırdan büyük olmalıdır.']);
        }
        if ($rate->isNegative() || $rate->isGreaterThanOrEqualTo(1000) || $rate->getScale() > 4) {
            throw ValidationException::withMessages(['annual_interest_rate' => 'Faiz oranı 0 ile 999,9999 arasında olmalıdır.']);
        }

        $start = CarbonImmutable::parse($data['start_date'])->startOfDay();
        $firstDue = CarbonImmutable::parse($data['first_due_date'])->startOfDay();
        if ($firstDue->lte($start)) {
            throw ValidationException::withMessages(['first_due_date' => 'İlk taksit tarihi kullandırım tarihinden sonra olmalıdır.']);
        }

        $account = Account::find($data['account_id']);
        if (! $account || $account->type !== AccountType::Bank || ! $account->is_active) {
            throw ValidationException::withMessages(['account_id' => 'Kredi aktif bir banka hesabına bağlanmalıdır.']);
        }

        return DB::transaction(function () use ($data, $principal, $rate, $start, $firstDue, $account, $user) {
            $loan = new Loan([
                'name' => $data['name'],
                'bank_name' => $data['bank_name'],
                'account_id' => $account->id,
                'start_date' => $start,
                'principal' => $principal,
                'annual_interest_rate' => (string) $rate->toScale(4),
                'term_months' => (int) $data['term_months'],
                'first_due_date' => $firstDue,
                'notes' => $data['notes'] ?? null,
            ]);
            $loan->created_by = $user->id;
            $loan->save();

            foreach ($this->calculator->annuity($principal, (string) $rate, $loan->term_months, $firstDue) as $row) {
                $loan->installments()->create($row);
            }

            $this->audit->log(AuditService::CREATED, $loan, new: $loan->only(['name', 'bank_name', 'account_id', 'start_date', 'principal', 'annual_interest_rate', 'term_months', 'first_due_date']), user: $user);

            if (! empty($data['record_disbursement'])) {
                $this->transactions->create([
                    'type' => 'inflow',
                    'date' => $start->toDateString(),
                    'amount' => $principal,
                    'to_account_id' => $account->id,
                    'category_id' => $this->categoryId('loan_received'),
                    'counterparty' => $loan->bank_name,
                    'description' => "Kredi kullanımı: {$loan->name}",
                ], $user, ['loan_id' => $loan->id]);
            }

            return $loan;
        });
    }

    /**
     * Taksiti öder: anapara ve faiz ayrı çıkış kayıtları. Taksitler sırayla ödenir.
     *
     * @return Collection<int, Transaction>
     */
    public function payInstallment(LoanInstallment $installment, string $date, int $accountId, User $user): Collection
    {
        return DB::transaction(function () use ($installment, $date, $accountId, $user) {
            /** @var LoanInstallment $inst */
            $inst = LoanInstallment::whereKey($installment->id)->lockForUpdate()->firstOrFail();
            $loan = Loan::whereKey($inst->loan_id)->lockForUpdate()->firstOrFail();

            if ($this->isPaid($inst)) {
                throw ValidationException::withMessages(['installment' => "{$inst->number}. taksit zaten ödenmiş."]);
            }
            if (CarbonImmutable::parse($date)->lt($loan->start_date)) {
                throw ValidationException::withMessages(['date' => 'Ödeme tarihi kredi başlangıç tarihinden önce olamaz.']);
            }

            $unpaidBefore = $loan->installments()->where('number', '<', $inst->number)->get()->reject(fn ($i) => $this->isPaid($i));
            if ($unpaidBefore->isNotEmpty()) {
                throw ValidationException::withMessages(['installment' => "Önce {$unpaidBefore->first()->number}. taksit ödenmelidir."]);
            }

            $common = [
                'type' => 'outflow',
                'date' => $date,
                'from_account_id' => $accountId,
                'counterparty' => $loan->bank_name,
                'reference_no' => "KREDI-{$loan->id}-T{$inst->number}",
            ];
            $links = ['loan_id' => $loan->id, 'loan_installment_id' => $inst->id];

            $created = collect();
            if (BigDecimal::of($inst->principal)->isPositive()) {
                $created->push($this->transactions->create($common + [
                    'amount' => $inst->principal,
                    'category_id' => $this->categoryId('loan_principal'),
                    'description' => "{$loan->name} – {$inst->number}. taksit anapara",
                ], $user, $links));
            }
            if (BigDecimal::of($inst->interest)->isPositive()) {
                $created->push($this->transactions->create($common + [
                    'amount' => $inst->interest,
                    'category_id' => $this->categoryId('loan_interest'),
                    'description' => "{$loan->name} – {$inst->number}. taksit faiz",
                ], $user, $links));
            }

            return $created;
        });
    }

    /**
     * Taksit ödemesini (anapara + faiz) birlikte iptal eder. Yalnızca son ödenen taksit iptal edilebilir.
     */
    public function reversePayment(LoanInstallment $installment, string $date, string $reason, User $user): void
    {
        DB::transaction(function () use ($installment, $date, $reason, $user) {
            $inst = LoanInstallment::whereKey($installment->id)->lockForUpdate()->firstOrFail();
            $payments = $inst->payments()->get();

            if ($payments->isEmpty()) {
                throw ValidationException::withMessages(['installment' => 'Bu taksit ödenmemiş.']);
            }

            $laterPaid = LoanInstallment::where('loan_id', $inst->loan_id)->where('number', '>', $inst->number)
                ->whereHas('payments')->exists();
            if ($laterPaid) {
                throw ValidationException::withMessages(['installment' => 'Önce sonraki taksitlerin ödemeleri iptal edilmelidir.']);
            }

            foreach ($payments as $payment) {
                $this->transactions->reverse($payment, $date, $reason, $user, allowLinked: true);
            }
        });
    }

    /**
     * Ödenmemiş taksitte banka planına göre tutar/tarih düzeltmesi.
     */
    public function updateInstallment(LoanInstallment $installment, array $data, User $user): LoanInstallment
    {
        if ($this->isPaid($installment)) {
            throw ValidationException::withMessages(['installment' => 'Ödenmiş taksit değiştirilemez.']);
        }

        try {
            $principal = Money::parse($data['principal']);
            $interest = Money::parse($data['interest']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['principal' => $e->getMessage()]);
        }
        if (BigDecimal::of($principal)->isNegative() || BigDecimal::of($interest)->isNegative()
            || BigDecimal::of($principal)->plus($interest)->isZero()) {
            throw ValidationException::withMessages(['principal' => 'Anapara ve faiz negatif olamaz, toplamları sıfırdan büyük olmalıdır.']);
        }

        return DB::transaction(function () use ($installment, $data, $principal, $interest, $user) {
            $original = $installment->getOriginal();
            $installment->fill([
                'principal' => $principal,
                'interest' => $interest,
                'due_date' => CarbonImmutable::parse($data['due_date']),
            ])->save();

            [$old, $new] = AuditService::diff($original, $installment);
            if ($new !== []) {
                $this->audit->log(AuditService::UPDATED, $installment, $old, $new, user: $user);
            }

            return $installment;
        });
    }

    public function isPaid(LoanInstallment $installment): bool
    {
        return $installment->payments()->exists();
    }

    /**
     * Kredi özeti. Ödenen tutarlar gerçek (iptal edilmemiş) hareketlerden hesaplanır.
     *
     * @return array{principal: string, planned_principal: string, plan_difference: string, paid_principal: string,
     *     paid_interest: string, remaining_principal: string, remaining_interest: string, paid_count: int,
     *     total_count: int, next: ?LoanInstallment, closed: bool}
     */
    public function summary(Loan $loan): array
    {
        $paid = DB::selectOne(<<<'SQL'
            SELECT
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'loan_principal'), 0)::numeric(18,2)::text AS principal,
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'loan_interest'), 0)::numeric(18,2)::text AS interest
            FROM transactions t JOIN categories c ON c.id = t.category_id
            WHERE t.loan_id = ? AND t.type = 'outflow'
        SQL, [$loan->id]);

        $installments = $loan->installments()->withExists('payments as is_paid')->get();
        $planned = $installments->reduce(fn ($c, $i) => $c->plus($i->principal), BigDecimal::zero());
        $unpaid = $installments->where('is_paid', false);
        $remainingInterest = $unpaid->reduce(fn ($c, $i) => $c->plus($i->interest), BigDecimal::zero());
        $remaining = BigDecimal::of($loan->principal)->minus($paid->principal);

        return [
            'principal' => (string) $loan->principal,
            'planned_principal' => (string) Money::of($planned),
            'plan_difference' => (string) Money::of($planned->minus($loan->principal)),
            'paid_principal' => $paid->principal,
            'paid_interest' => $paid->interest,
            'remaining_principal' => (string) Money::of($remaining),
            'remaining_interest' => (string) Money::of($remainingInterest),
            'paid_count' => $installments->where('is_paid', true)->count(),
            'total_count' => $installments->count(),
            'next' => $unpaid->first(),
            'closed' => $unpaid->isEmpty(),
        ];
    }

    private function categoryId(string $code): int
    {
        return Category::where('code', $code)->valueOrFail('id');
    }
}
