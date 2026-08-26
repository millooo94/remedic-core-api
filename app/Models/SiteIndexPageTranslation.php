<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteIndexPageTranslation extends Model
{
    protected $fillable = ['site_index_page_id', 'locale', 'title', 'slug', 'content', 'seo_title', 'seo_description', 'seo_h1', 'publication_state', 'source_revision', 'reviewed_source_revision'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class, 'content' => 'array'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SiteIndexPage::class, 'site_index_page_id');
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publication_state === 'published' && filled($this->title) && filled($this->slug) && ($this->locale === SupportedLocale::IT || $this->source_revision === $this->reviewed_source_revision);
    }
}
