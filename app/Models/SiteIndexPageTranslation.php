<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteIndexPageTranslation extends Model
{
    protected $fillable = ['site_index_page_id', 'locale', 'title', 'slug', 'content', 'seo_title', 'seo_description', 'seo_h1', 'source_revision', 'reviewed_source_revision'];

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
        return filled($this->title) && filled($this->slug);
    }
}
