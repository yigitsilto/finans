<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    protected $guarded = ['id', 'company_id', 'created_by'];

    protected function casts(): array
    {
        return ['share_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PartnerAdjustment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
