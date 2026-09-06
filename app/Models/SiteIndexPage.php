<?php

namespace App\Models;

use App\Models\Concerns\HasSectionsAndFaqs;
use App\Models\Concerns\SynchronizesLocalizedSingleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteIndexPage extends Model
{
    use HasSectionsAndFaqs;
    use SynchronizesLocalizedSingleton;

    public function translations(): HasMany
    {
        return $this->hasMany(SiteIndexPageTranslation::class);
    }

    protected $fillable = ['internal_key', 'title', 'slug', 'content', 'configuration', 'hero_video_path', 'hero_poster_path', 'intro_split_image_path', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots', 'og_image_path', 'twitter_title', 'twitter_description', 'twitter_image_path', 'is_active'];

    protected function casts(): array
    {
        return ['content' => 'array', 'configuration' => 'array', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function isPubliclyAvailable(): bool
    {
        return (bool) $this->is_active;
    }
}
