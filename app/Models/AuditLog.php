<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Denetim kaydı değiştirilemez ve silinemez.
        static::updating(fn () => throw new LogicException('Denetim kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Denetim kayıtları silinemez.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
