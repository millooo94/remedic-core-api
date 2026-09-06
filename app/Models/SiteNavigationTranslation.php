<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteNavigationTranslation extends Model
{
    protected $fillable = ['site_navigation_id', 'locale', 'configuration', 'source_revision', 'reviewed_source_revision'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class, 'configuration' => 'array'];
    }

    public function navigation(): BelongsTo
    {
        return $this->belongsTo(SiteNavigation::class, 'site_navigation_id');
    }

    public function isPubliclyAvailable(): bool
    {
        return is_array($this->configuration);
    }
}
