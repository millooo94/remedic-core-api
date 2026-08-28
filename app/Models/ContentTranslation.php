<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentTranslation extends Model
{
    protected $fillable = [
        'translatable_type', 'translatable_id', 'locale', 'title', 'slug', 'excerpt', 'intro_text', 'short_description',
        'subtitle', 'category_label', 'body', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description',
        'label', 'description',
        'publication_state', 'source_revision', 'reviewed_source_revision',
    ];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeLocale(Builder $query, SupportedLocale|string $locale): Builder
    {
        return $query->where('locale', $locale instanceof SupportedLocale ? $locale->value : $locale);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publication_state', 'published');
    }

    public function isComplete(): bool
    {
        if ($this->translatable_type === ConsentCategory::class) {
            return filled($this->label) && filled($this->description);
        }

        return filled($this->title) && filled($this->slug);
    }

    public function needsReview(): bool
    {
        return $this->locale !== SupportedLocale::IT && $this->source_revision !== $this->reviewed_source_revision;
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publication_state === 'published' && $this->isComplete() && ! $this->needsReview();
    }
}
