<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kasa, banka veya POS hesabı. Bakiye bu modelde tutulmaz: BalanceService ile hesaplanır.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $guarded = ['id', 'company_id', 'created_by'];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'iban' => 'encrypted',
            'allow_negative' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function settlementAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settlement_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function maskedIban(): ?string
    {
        if (! $this->iban) {
            return null;
        }

        return substr($this->iban, 0, 4).' **** **** '.substr($this->iban, -4);
    }

    public function displayName(): string
    {
        return $this->bank_name ? "{$this->name} ({$this->bank_name})" : $this->name;
    }
}
