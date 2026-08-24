<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpecializationWebProfile extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $attributes = [
        'is_web_enabled' => false,
        'list_sort_order' => 0,
        'is_local_seo_enabled' => true,
        'robots' => 'index,follow',
    ];

    protected $fillable = [
        'specialization_id',
        'slug',
        'short_description',
        'is_web_enabled',
        'list_sort_order',
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
        'legacy_content',
    ];

    protected function casts(): array
    {
        return [
            'is_web_enabled' => 'boolean',
            'is_local_seo_enabled' => 'boolean',
            'list_sort_order' => 'integer',
            'robots' => RobotsValue::class,
            'legacy_content' => 'array',
        ];
    }

    public function specialization(): BelongsTo
    {
        return $this->belongsTo(Specialization::class);
    }

    public function scopeEffectivelyVisible(Builder $query): Builder
    {
        return $query
            ->where('is_web_enabled', true)
            ->whereHas('specialization', fn (Builder $master) => $master->where('is_active', true));
    }
}
