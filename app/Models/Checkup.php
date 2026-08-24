<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Checkup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'display_name',
        'price_amount',
        'indicative_duration_minutes',
        'is_active',
        'organizational_notes',
        'featured_image_path',
        'icon_path',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'indicative_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckupService::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'checkup_services')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->withTimestamps();
    }

    public function webProfile(): HasOne
    {
        return $this->hasOne(CheckupWebProfile::class);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn())
            ->where('is_active', true)
            ->whereHas('webProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true));
    }

    public function scopeOperationallyAvailable(Builder $query): Builder
    {
        return $query
            ->whereNull($query->getModel()->getQualifiedDeletedAtColumn())
            ->where('is_active', true)
            ->whereHas('items')
            ->whereDoesntHave('items', fn (Builder $item) => $item
                ->whereDoesntHave('service', fn (Builder $service) => $service
                    ->whereNull('services.deleted_at')
                    ->where('is_active', true)));
    }

    public function isEffectivelyVisible(): bool
    {
        return ! $this->trashed() && (bool) $this->is_active && (bool) $this->webProfile?->is_web_enabled;
    }

    public function isOperationallyAvailable(): bool
    {
        if ($this->trashed() || ! $this->is_active) {
            return false;
        }

        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('service')->get();

        return $items->isNotEmpty() && $items->every(
            fn (CheckupService $item): bool => $item->service !== null
                && ! $item->service->trashed()
                && (bool) $item->service->is_active
        );
    }
}
