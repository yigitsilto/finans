<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loan extends Model
{
    protected $guarded = ['id', 'company_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'first_due_date' => 'date',
            'principal' => 'decimal:2',
            'annual_interest_rate' => 'decimal:4',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('number');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
