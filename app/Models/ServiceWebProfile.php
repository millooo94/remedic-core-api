<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceWebProfile extends Model
{
    use HasContentTranslations;
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $attributes = [
        'is_web_enabled' => false,
        'is_local_seo_enabled' => true,
        'robots' => 'index,follow',
    ];

    protected $fillable = [
        'service_id',
        'public_slug',
        'short_description',
        'is_web_enabled',
        'is_diagnostic',
        'is_aesthetic_medicine',
        'aesthetic_category',
        'seo_title',
        'local_seo_title',
        'seo_description',
        'local_seo_description',
        'seo_h1',
        'local_seo_h1',
        'is_local_seo_enabled',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image_path',
        'twitter_title',
        'twitter_description',
        'twitter_image_path',
        'legacy_content',
    ];

    protected function casts(): array
    {
        return [
            'is_web_enabled' => 'boolean',
            'is_diagnostic' => 'boolean',
            'is_aesthetic_medicine' => 'boolean',
            'is_local_seo_enabled' => 'boolean',
            'robots' => RobotsValue::class,
            'legacy_content' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->where('is_web_enabled', true)
            ->whereHas('service', fn (Builder $master) => $master->effectivelyVisible());
    }

    public function isEffectivelyVisible(): bool
    {
        return (bool) $this->is_web_enabled && (bool) $this->service?->isEffectivelyVisible();
    }
}
