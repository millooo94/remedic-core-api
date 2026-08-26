<?php

namespace App\Models;

use App\Enums\SitePopupSourceType;
use App\Models\Concerns\SynchronizesLocalizedSingleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SitePopup extends Model
{
    use SynchronizesLocalizedSingleton;

    public function translations(): HasMany
    {
        return $this->hasMany(SitePopupTranslation::class);
    }

    protected $fillable = [
        'is_active', 'source_type', 'promotion_id', 'event_id', 'start_at', 'end_at', 'eyebrow', 'title', 'body', 'image_path',
        'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target',
        'campaign_version',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'source_type' => SitePopupSourceType::class, 'start_at' => 'datetime', 'end_at' => 'datetime', 'campaign_version' => 'integer'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function status(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->start_at?->isFuture()) {
            return 'scheduled';
        }
        if ($this->end_at?->lte(now())) {
            return 'expired';
        }

        return 'active';
    }

    public function isEligible(): bool
    {
        return $this->status() === 'active';
    }

    public function hasValidSource(): bool
    {
        return match ($this->source_type) {
            SitePopupSourceType::MANUAL => $this->promotion_id === null && $this->event_id === null,
            SitePopupSourceType::PROMOTION => $this->promotion !== null && $this->promotion->isEffectivelyAvailable(),
            SitePopupSourceType::EVENT => $this->event !== null && $this->event->isEffectivelyAvailable(),
        };
    }
}
