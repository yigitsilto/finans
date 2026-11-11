<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = ['id', 'company_id', 'created_by'];

    protected $hidden = ['tax_number'];

    protected $attributes = ['is_active' => true, 'type' => 'individual'];

    protected function casts(): array
    {
        return ['tax_number' => 'encrypted', 'is_active' => 'boolean'];
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return $this->type === 'corporate' ? 'Kurumsal' : 'Bireysel';
    }

    public function maskedTaxNumber(): ?string
    {
        return $this->tax_number ? str_repeat('*', strlen($this->tax_number) - 3).substr($this->tax_number, -3) : null;
    }
}
