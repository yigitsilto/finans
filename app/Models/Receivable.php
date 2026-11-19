<?php

namespace App\Models;

use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Müşteri alacağı. Tahsil edilen tutar bağlı nakit girişlerinin toplamıdır (ters kayıtlar negatif).
 * Listelerde `withCollected()` ile tek sorguda hesaplanır.
 */
class Receivable extends Model
{
    public const STATUS_LABELS = [
        'open' => 'Açık',
        'partial' => 'Kısmi tahsil',
        'paid' => 'Tahsil edildi',
        'cancelled' => 'İptal',
    ];

    protected $guarded = ['id', 'company_id', 'created_by', 'cancelled_at', 'cancel_reason', 'cancelled_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Alacaklar silinemez; iptal edin.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('date')->orderBy('id');
    }

    /** Tahsil edilen tutarı `collected` sütunu olarak ekler. */
    public function scopeWithCollected(Builder $query): Builder
    {
        return $query->addSelect([
            'collected' => Transaction::selectRaw('CAST(COALESCE(SUM(amount), 0) AS DECIMAL(18,2))')
                ->whereColumn('transactions.receivable_id', 'receivables.id'),
        ]);
    }

    public function collectedAmount(): string
    {
        if (array_key_exists('collected', $this->attributes)) {
            return (string) $this->attributes['collected'];
        }

        return (string) Money::of(Transaction::where('receivable_id', $this->id)->sum('amount') ?: '0');
    }

    public function remainingAmount(): string
    {
        return (string) Money::of(BigDecimal::of($this->amount)->minus($this->collectedAmount()));
    }

    public function status(): string
    {
        if ($this->cancelled_at) {
            return 'cancelled';
        }
        $collected = BigDecimal::of($this->collectedAmount());

        return match (true) {
            $collected->isGreaterThanOrEqualTo($this->amount) => 'paid',
            $collected->isPositive() => 'partial',
            default => 'open',
        };
    }

    public function isOverdue(): bool
    {
        return ! $this->cancelled_at && $this->status() !== 'paid' && $this->due_date->lt(today());
    }
}
