<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Müşteri alacakları. Tahsilat = alacağa bağlı nakit girişi ("Müşteri tahsilatları").
 * Kalan tutar her zaman (alacak − tahsilatlar) olarak hesaplanır; fazla tahsilat hem burada
 * hem de veritabanı trigger'ında engellenir.
 */
class ReceivableService
{
    private const AUDIT_FIELDS = ['customer_id', 'reference_no', 'insurance_company', 'description', 'amount', 'issue_date', 'due_date'];

    public function __construct(
        private readonly TransactionService $transactions,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Receivable
    {
        $amount = $this->positiveAmount($data['amount']);
        $this->assertDates($data['issue_date'], $data['due_date']);

        if (! Customer::active()->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Aktif bir müşteri seçilmelidir.']);
        }

        return DB::transaction(function () use ($data, $amount, $user) {
            $receivable = new Receivable([
                'customer_id' => $data['customer_id'],
                'reference_no' => $data['reference_no'],
                'insurance_company' => $data['insurance_company'] ?? null,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
            ]);
            $receivable->created_by = $user->id;
            $receivable->save();

            $this->audit->log(AuditService::CREATED, $receivable, new: $receivable->only(self::AUDIT_FIELDS), user: $user);

            return $receivable;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Receivable $receivable, array $data, User $user): Receivable
    {
        $amount = $this->positiveAmount($data['amount']);
        $this->assertDates($data['issue_date'], $data['due_date']);

        return DB::transaction(function () use ($receivable, $data, $amount, $user) {
            $receivable = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($receivable->cancelled_at) {
                throw ValidationException::withMessages(['receivable' => 'İptal edilmiş alacak düzenlenemez.']);
            }

            $collected = $receivable->collectedAmount();
            if (BigDecimal::of($amount)->isLessThan($collected)) {
                throw ValidationException::withMessages(['amount' => 'Alacak tutarı tahsil edilen tutarın ('.Money::format($collected).') altına düşürülemez.']);
            }

            $original = $receivable->getOriginal();
            $receivable->fill([
                'reference_no' => $data['reference_no'],
                'insurance_company' => $data['insurance_company'] ?? null,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'],
            ])->save();

            [$old, $new] = AuditService::diff($original, $receivable);
            if ($new !== []) {
                $this->audit->log(AuditService::UPDATED, $receivable, $old, $new, user: $user);
            }

            return $receivable;
        });
    }

    /**
     * Kısmi veya tam tahsilat. Tutar kalan alacağı aşamaz.
     *
     * @param  array{date: string, amount: string, account_id: int, pos_settlement_date?: ?string,
     *     reference_no?: ?string, description?: ?string}  $data
     */
    public function collect(Receivable $receivable, array $data, User $user): Transaction
    {
        $amount = $this->positiveAmount($data['amount']);

        return DB::transaction(function () use ($receivable, $data, $amount, $user) {
            // Alacak satırını kilitle: eşzamanlı iki tahsilat kalan tutarı birlikte aşamasın.
            $receivable = Receivable::with('customer')->whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($receivable->cancelled_at) {
                throw ValidationException::withMessages(['receivable' => 'İptal edilmiş alacağa tahsilat girilemez.']);
            }
            if (CarbonImmutable::parse($data['date'])->lt($receivable->issue_date)) {
                throw ValidationException::withMessages(['date' => 'Tahsilat tarihi alacak tarihinden önce olamaz.']);
            }

            $remaining = $receivable->remainingAmount();
            if (BigDecimal::of($amount)->isGreaterThan($remaining)) {
                throw ValidationException::withMessages([
                    'amount' => 'Tahsilat tutarı kalan alacağı ('.Money::format($remaining).') aşamaz.',
                ]);
            }

            return $this->transactions->create([
                'type' => 'inflow',
                'date' => $data['date'],
                'amount' => $amount,
                'to_account_id' => $data['account_id'],
                'category_id' => Category::where('code', 'customer_collection')->valueOrFail('id'),
                'counterparty' => $receivable->customer->name,
                'reference_no' => $data['reference_no'] ?? $receivable->reference_no,
                'description' => $data['description'] ?? "Tahsilat: {$receivable->reference_no}",
                'pos_settlement_date' => $data['pos_settlement_date'] ?? null,
            ], $user, ['receivable_id' => $receivable->id]);
        });
    }

    /** Tahsilatı olmayan alacağı iptal eder (silmez). */
    public function cancel(Receivable $receivable, string $reason, User $user): Receivable
    {
        return DB::transaction(function () use ($receivable, $reason, $user) {
            $receivable = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();

            if ($receivable->cancelled_at) {
                throw ValidationException::withMessages(['receivable' => 'Alacak zaten iptal edilmiş.']);
            }
            if (! BigDecimal::of($receivable->collectedAmount())->isZero()) {
                throw ValidationException::withMessages(['receivable' => 'Tahsilatı olan alacak iptal edilemez. Önce tahsilatları iptal edin.']);
            }

            $receivable->forceFill(['cancelled_at' => now(), 'cancel_reason' => $reason, 'cancelled_by' => $user->id])->save();
            $this->audit->log(AuditService::REVERSED, $receivable, new: ['reason' => $reason], user: $user);

            return $receivable;
        });
    }

    private function positiveAmount(string $input): string
    {
        try {
            $amount = Money::parse($input);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if (! BigDecimal::of($amount)->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Tutar sıfırdan büyük olmalıdır.']);
        }

        return $amount;
    }

    private function assertDates(string $issue, string $due): void
    {
        $issue = CarbonImmutable::parse($issue);
        if ($issue->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['issue_date' => 'Alacak tarihi ileri bir tarih olamaz.']);
        }
        if (CarbonImmutable::parse($due)->lt($issue)) {
            throw ValidationException::withMessages(['due_date' => 'Vade tarihi alacak tarihinden önce olamaz.']);
        }
    }
}
