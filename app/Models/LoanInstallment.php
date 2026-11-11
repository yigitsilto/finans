<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanInstallment extends Model
{
    protected $guarded = ['id', 'total'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** Bu taksite ait iptal edilmemiş ödeme hareketleri (anapara + faiz). */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class)->whereNull('reversal_of_id')->whereDoesntHave('reversal');
    }
}
