<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePopupTranslation extends Model
{
    protected $fillable = ['site_popup_id', 'locale', 'eyebrow', 'title', 'body', 'primary_cta_label', 'secondary_cta_label', 'publication_state', 'source_revision', 'reviewed_source_revision'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class];
    }

    public function popup(): BelongsTo
    {
        return $this->belongsTo(SitePopup::class, 'site_popup_id');
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publication_state === 'published' && filled($this->title) && ($this->locale === SupportedLocale::IT || $this->source_revision === $this->reviewed_source_revision);
    }
}
