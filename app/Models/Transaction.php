<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Finansal hareket. Yalnızca TransactionService üzerinden oluşturulur/güncellenir.
 * Fiziksel silme yasaktır; iptal ters kayıtla yapılır.
 */
class Transaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'date' => 'date',
            'pos_settlement_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Finansal hareketler silinemez; ters kayıt kullanın.'));
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** Bu POS girişini bankaya aktaran (iptal edilmemiş) transfer. */
    public function activeSettlement(): HasOne
    {
        return $this->hasOne(self::class, 'settles_transaction_id')->whereDoesntHave('reversal');
    }

    public function settles(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settles_transaction_id');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->relationLoaded('reversal') ? $this->reversal !== null : $this->reversal()->exists();
    }

    /** İptal edilmemiş ve kendisi ters kayıt olmayan hareketler. */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('reversal_of_id')->whereDoesntHave('reversal');
    }
}
