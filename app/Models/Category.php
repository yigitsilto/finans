<?php

namespace App\Models;

use App\Enums\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activity' => Activity::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Hareket yalnızca alt kategorisi olmayan (yaprak) kategoriye yazılabilir. */
    public function scopeSelectable(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction)
            ->where('is_active', true)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('categories as c')->whereColumn('c.parent_id', 'categories.id'));
    }

    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /**
     * Select kutusu için gruplanmış seçenekler: [grup adı => [id => ad]].
     *
     * @return Collection<string, array<int, string>>
     */
    public static function groupedOptions(string $direction): Collection
    {
        return self::selectable($direction)->with('parent')->orderBy('sort')->get()
            ->sortBy(fn ($c) => [$c->parent?->sort ?? $c->sort, $c->parent ? $c->sort : 0])
            ->groupBy(fn ($c) => $c->parent?->name ?? 'Genel')
            ->map(fn ($items) => $items->pluck('name', 'id')->all());
    }
}
