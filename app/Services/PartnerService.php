<?php

namespace App\Services;

use App\Models\Partner;
use App\Models\PartnerAdjustment;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ortak cari hesabı.
 *
 * Sermaye, cariden AYRI izlenir (özkaynak). Cari bakiye şirket ile ortak arasındaki borç/alacağı gösterir:
 *   cari = ortaktan alınan borç − ortağa borç geri ödemesi − ortaklara ödemeler + alacak düzeltmeleri − borç düzeltmeleri
 *   > 0 : şirket ortağa borçlu      < 0 : ortak şirkete borçlu
 * Ortak hareketleri finansman faaliyetidir, operasyonel gider olarak sınıflandırılmaz.
 */
class PartnerService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{capital: string, loans_in: string, loan_repayments: string, payments: string,
     *     adjustments: string, balance: string}
     */
    public function summary(Partner $partner): array
    {
        return $this->summaries(collect([$partner]))[$partner->id];
    }

    /**
     * @param  Collection<int, Partner>  $partners
     * @return array<int, array<string, string>>
     */
    public function summaries(Collection $partners): array
    {
        $ids = $partners->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        $cash = collect(DB::select(<<<SQL
            SELECT t.partner_id,
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'partner_capital'), 0)::numeric(18,2)::text AS capital,
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'partner_loan_received'), 0)::numeric(18,2)::text AS loans_in,
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'partner_loan_repayment'), 0)::numeric(18,2)::text AS loan_repayments,
                COALESCE(SUM(t.amount) FILTER (WHERE c.code = 'partner_payment'), 0)::numeric(18,2)::text AS payments
            FROM transactions t JOIN categories c ON c.id = t.category_id
            WHERE t.partner_id IN ({$in})
            GROUP BY t.partner_id
        SQL, $ids))->keyBy('partner_id');

        $adjustments = collect(DB::select(<<<SQL
            SELECT partner_id,
                COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0)::numeric(18,2)::text AS net
            FROM partner_adjustments WHERE partner_id IN ({$in}) GROUP BY partner_id
        SQL, $ids))->keyBy('partner_id');

        $result = [];
        foreach ($ids as $id) {
            $row = $cash->get($id);
            $capital = $row->capital ?? '0.00';
            $loansIn = $row->loans_in ?? '0.00';
            $repayments = $row->loan_repayments ?? '0.00';
            $payments = $row->payments ?? '0.00';
            $adj = $adjustments->get($id)->net ?? '0.00';

            $balance = BigDecimal::of($loansIn)->minus($repayments)->minus($payments)->plus($adj);

            $result[$id] = [
                'capital' => $capital,
                'loans_in' => $loansIn,
                'loan_repayments' => $repayments,
                'payments' => $payments,
                'adjustments' => $adj,
                'balance' => (string) Money::of($balance),
            ];
        }

        return $result;
    }

    /**
     * Cari ekstresi: nakit hareketleri ve nakit dışı düzeltmeler tarih sırasıyla, yürüyen cari bakiye ile.
     * Sermaye satırları gösterilir ama cari bakiyeyi etkilemez.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function ledger(Partner $partner): Collection
    {
        $cash = $partner->transactions()->with(['category', 'fromAccount', 'toAccount', 'reversal'])->get()
            ->map(function ($t) {
                $code = $t->category->code;
                $effect = match ($code) {
                    'partner_capital' => '0',
                    'partner_loan_received' => (string) $t->amount,
                    default => (string) Money::of($t->amount)->negated(), // ödeme ve geri ödemeler cariyi azaltır
                };

                return [
                    'date' => $t->date, 'sort' => 't'.$t->id, 'kind' => 'cash', 'model' => $t,
                    'label' => $t->category->name, 'description' => $t->description,
                    'amount' => (string) $t->amount, 'effect' => $effect, 'capital' => $code === 'partner_capital',
                ];
            });

        $adjustments = $partner->adjustments()->with('reversal')->get()->map(fn (PartnerAdjustment $a) => [
            'date' => $a->date, 'sort' => 'a'.$a->id, 'kind' => 'adjustment', 'model' => $a,
            'label' => $a->direction === 'credit' ? 'Alacak düzeltmesi (nakitsiz)' : 'Borç düzeltmesi (nakitsiz)',
            'description' => $a->description,
            'amount' => (string) $a->amount,
            'effect' => $a->direction === 'credit' ? (string) $a->amount : (string) Money::of($a->amount)->negated(),
            'capital' => false,
        ]);

        $running = BigDecimal::zero();

        return $cash->concat($adjustments)
            ->sortBy([['date', 'asc'], ['sort', 'asc']])
            ->values()
            ->map(function ($row) use (&$running) {
                $running = $running->plus($row['effect']);
                $row['balance'] = (string) Money::of($running);

                return $row;
            });
    }

    public function addAdjustment(Partner $partner, array $data, User $user): PartnerAdjustment
    {
        try {
            $amount = Money::parse($data['amount']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if (! BigDecimal::of($amount)->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Tutar sıfırdan büyük olmalıdır.']);
        }
        if (CarbonImmutable::parse($data['date'])->isFuture()) {
            throw ValidationException::withMessages(['date' => 'İleri tarihli kayıt girilemez.']);
        }

        return DB::transaction(function () use ($partner, $data, $amount, $user) {
            $adjustment = $partner->adjustments()->create([
                'date' => $data['date'],
                'direction' => $data['direction'],
                'amount' => $amount,
                'description' => $data['description'],
                'created_by' => $user->id,
            ]);
            $this->audit->log(AuditService::CREATED, $adjustment, new: $adjustment->only(['partner_id', 'date', 'direction', 'amount', 'description']), user: $user);

            return $adjustment;
        });
    }

    public function reverseAdjustment(PartnerAdjustment $adjustment, string $reason, User $user): PartnerAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $user) {
            $original = PartnerAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($original->reversal_of_id || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['adjustment' => 'Bu kayıt iptal edilemez (zaten iptal edilmiş veya ters kayıt).']);
            }

            $reversal = PartnerAdjustment::create([
                'partner_id' => $original->partner_id,
                'date' => today(),
                'direction' => $original->direction,
                'amount' => (string) Money::of($original->amount)->negated(),
                'description' => "İptal (#{$original->id}): {$reason}",
                'reversal_of_id' => $original->id,
                'created_by' => $user->id,
            ]);
            $this->audit->log(AuditService::REVERSED, $original, new: ['reversal_id' => $reversal->id, 'reason' => $reason], user: $user);

            return $reversal;
        });
    }
}
