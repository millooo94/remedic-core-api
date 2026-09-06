<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConventionPartnerWebProfile extends Model
{
    use HasContentTranslations;
    use HasSectionsAndFaqs;

    protected $attributes = [
        'is_web_enabled' => false,
        'is_local_seo_enabled' => true,
        'robots' => 'noindex,nofollow',
    ];

    protected $fillable = [
        'convention_partner_id', 'title', 'public_slug', 'is_web_enabled', 'seo_title', 'seo_description', 'seo_h1',
        'local_seo_title', 'local_seo_description', 'local_seo_h1', 'is_local_seo_enabled',
        'canonical_url', 'robots', 'og_title', 'og_description', 'og_image_path',
        'twitter_title', 'twitter_description', 'twitter_image_path',
    ];

    protected function casts(): array
    {
        return ['is_web_enabled' => 'boolean', 'is_local_seo_enabled' => 'boolean', 'robots' => RobotsValue::class];
    }

    public function conventionPartner(): BelongsTo
    {
        return $this->belongsTo(ConventionPartner::class);
    }
}
