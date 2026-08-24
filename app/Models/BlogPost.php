<?php

namespace App\Models;

use App\Enums\PublicationState;
use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'title',
        'slug',
        'excerpt',
        'cover_image',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'related_article_slugs',
        'is_active',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'robots' => RobotsValue::class,
            'is_active' => 'boolean',
            'related_article_slugs' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopePublicationState(Builder $query, PublicationState|string $state): Builder
    {
        $state = $state instanceof PublicationState ? $state : PublicationState::from($state);

        return match ($state) {
            PublicationState::Draft => $query->where('is_active', true)->whereNull('published_at'),
            PublicationState::Scheduled => $query->where('is_active', true)->where('published_at', '>', now()),
            PublicationState::Published => $query->active()->published(),
            PublicationState::Suspended => $query->where('is_active', false),
        };
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isPubliclyAvailable(): bool
    {
        return (bool) $this->is_active && $this->isPublished();
    }

    public function publicationState(): PublicationState
    {
        if (! $this->is_active) {
            return PublicationState::Suspended;
        }

        if ($this->published_at === null) {
            return PublicationState::Draft;
        }

        return $this->published_at->isFuture()
            ? PublicationState::Scheduled
            : PublicationState::Published;
    }
}
