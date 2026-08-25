<?php

namespace App\Models;

use App\Enums\PublicationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SiteIndexPage extends Model
{
    protected $fillable = ['internal_key', 'title', 'slug', 'content', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots', 'is_active', 'published_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_active' => 'boolean', 'published_at' => 'datetime'];
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
