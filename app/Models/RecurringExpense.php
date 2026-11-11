<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringExpense extends Model
{
    protected $guarded = ['id', 'company_id', 'created_by'];

    /** Veritabanı varsayılanıyla aynı; kaydedilmeden önce de doğru davranması için. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Verilen ay için vade günü (kısa aylarda ayın son günü). */
    public function dueDateFor(CarbonImmutable $period): CarbonImmutable
    {
        $period = $period->startOfMonth();

        return $period->setDay(min($this->day_of_month, $period->daysInMonth));
    }

    public function appliesTo(CarbonImmutable $period): bool
    {
        $start = $period->startOfMonth();
        $end = $period->endOfMonth();

        return $this->is_active
            && $this->start_date->lte($end)
            && ($this->end_date === null || $this->end_date->gte($start));
    }
}
