<?php

namespace App\Models;

use App\Enums\PageTemplate;
use App\Enums\RobotsValue;
use App\Models\Concerns\HasContentTranslations;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    use HasContentTranslations;
    use HasFactory;
    use HasSectionsAndFaqs;

    public const HOME_SLUG = 'home';

    public const HOME_INTERNAL_KEY = 'home';

    public const CENTER_INTERNAL_KEY = 'center';

    public const CENTER_SLUG = 'il-centro';

    public const WHY_CHOOSE_US_INTERNAL_KEY = 'why_choose_us';

    public const WHY_CHOOSE_US_SLUG = 'perche-sceglierci';

    public const PLUS_HEALTH_PROTOCOL_INTERNAL_KEY = 'plus_health_protocol';

    public const PLUS_HEALTH_PROTOCOL_SLUG = 'protocollo-piu-salute';

    public const CONTACT_INTERNAL_KEY = 'contact';

    public const CONTACT_SLUG = 'contatti';

    public const CONVENTIONS_NETWORK_INTERNAL_KEY = 'conventions_network';

    public const CONVENTIONS_NETWORK_SLUG = 'convenzioni-e-network';

    public const CAREERS_INTERNAL_KEY = 'careers';

    public const CAREERS_SLUG = 'lavora-con-noi';

    public const TERMS_OF_SERVICE_INTERNAL_KEY = 'terms_of_service';

    public const TERMS_OF_SERVICE_SLUG = 'termini-di-servizio';

    public const LEGACY_CHECKUP_SLUGS = [
        'check-up',
        'check-up-donna',
        'check-up-uomo',
        'check-up-personalizzato',
        'check-up-cardiologico',
        'check-up-dermatologico',
        'check-up-ginecologico',
        'check-up-urologico',
        'check-up-endocrinologico',
    ];

    protected $fillable = [
        'legacy_backend_id',
        'internal_key',
        'title',
        'slug',
        'template',
        'content_kind',
        'custom_html',
        'custom_css',
        'custom_javascript',
        'excerpt',
        'intro_text',
        'hero_image_path',
        'hero_image_alt',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'og_image_path',
        'twitter_title',
        'twitter_description',
        'twitter_image_path',
        'meta_author',
        'meta_creator',
        'meta_keywords',
        'faq_enabled',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'template' => PageTemplate::class,
            'robots' => RobotsValue::class,
            'faq_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isPubliclyAvailable(): bool
    {
        return (bool) $this->is_active;
    }

    public function isLegacyCheckupPage(): bool
    {
        return in_array($this->slug, self::LEGACY_CHECKUP_SLUGS, true);
    }

    public function isHomePage(): bool
    {
        return $this->internal_key === self::HOME_INTERNAL_KEY;
    }

    public function isCustom(): bool
    {
        return $this->content_kind === 'custom';
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PageReview::class);
    }

    public function featuredReviews(): HasMany
    {
        return $this->hasMany(PageFeaturedReview::class);
    }
}
