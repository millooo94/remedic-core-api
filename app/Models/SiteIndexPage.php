<?php

namespace App\Models;

use App\Enums\PublicationState;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SiteIndexPage extends Model
{
    use HasSectionsAndFaqs;

    protected $fillable = ['internal_key', 'title', 'slug', 'content', 'configuration', 'hero_video_path', 'hero_poster_path', 'intro_split_image_path', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'configuration' => 'array', 'is_active' => 'boolean', 'published_at' => 'datetime'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function publicationState(): PublicationState
    {
        if (! $this->is_active) {
            return PublicationState::Suspended;
        } if (! $this->published_at) {
            return PublicationState::Draft;
        }

        return $this->published_at->isFuture() ? PublicationState::Scheduled : PublicationState::Published;
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->is_active && $this->published_at?->lte(now());
    }
}
