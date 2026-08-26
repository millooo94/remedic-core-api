<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckupWebProfile extends Model
{
    use HasContentTranslations;
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $attributes = [
        'is_web_enabled' => false,
        'list_sort_order' => 0,
        'is_local_seo_enabled' => true,
        'robots' => 'index,follow',
    ];

    protected $fillable = [
        'checkup_id', 'public_slug', 'short_description', 'category_label',
        'is_web_enabled', 'list_sort_order', 'seo_title', 'local_seo_title',
        'seo_description', 'local_seo_description', 'seo_h1', 'local_seo_h1',
        'is_local_seo_enabled', 'canonical_url', 'robots', 'og_title',
        'og_description', 'legacy_content',
    ];

    protected function casts(): array
    {
        return [
            'is_web_enabled' => 'boolean',
            'list_sort_order' => 'integer',
            'is_local_seo_enabled' => 'boolean',
            'robots' => RobotsValue::class,
            'legacy_content' => 'array',
        ];
    }

    public function checkup(): BelongsTo
    {
        return $this->belongsTo(Checkup::class);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query->where('is_web_enabled', true)
            ->whereHas('checkup', fn (Builder $master) => $master->effectivelyVisible());
    }

    public function isEffectivelyVisible(): bool
    {
        return (bool) $this->is_web_enabled && (bool) $this->checkup?->isEffectivelyVisible();
    }
}
