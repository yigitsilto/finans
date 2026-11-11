<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Ortak carisinde nakit hareketi olmayan kayıt. Silinmez; ters kayıtla iptal edilir. */
class PartnerAdjustment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Cari kayıtları silinemez; ters kayıt kullanın.'));
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('reversal_of_id')->whereDoesntHave('reversal');
    }
}
