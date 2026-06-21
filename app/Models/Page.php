<?php

namespace App\Models;

use App\Enums\PageTemplate;
use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    public const HOME_SLUG = 'home';

    protected $fillable = [
        'legacy_backend_id',
        'internal_key',
        'title',
        'slug',
        'template',
        'excerpt',
        'intro_text',
        'hero_image_path',
        'hero_image_alt',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image_path',
        'twitter_title',
        'twitter_description',
        'twitter_image_path',
        'meta_author',
        'meta_creator',
        'meta_keywords',
        'faq_enabled',
        'is_active',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'template' => PageTemplate::class,
            'robots' => RobotsValue::class,
            'faq_enabled' => 'boolean',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->whereNull('published_at')
                ->orWhere('published_at', '<=', now());
        });
    }

    public function isPublished(): bool
    {
        return $this->published_at === null || $this->published_at->lessThanOrEqualTo(now());
    }

    public function isPubliclyAvailable(): bool
    {
        return (bool) $this->is_active && $this->isPublished();
    }
}
