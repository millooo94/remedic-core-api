<?php

namespace App\Models;

use App\Enums\PageTemplate;
use App\Enums\PublicationState;
use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    public const HOME_SLUG = 'home';

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
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'template' => PageTemplate::class,
            'robots' => RobotsValue::class,
            'faq_enabled' => 'boolean',
            'is_active' => 'boolean',
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

    public function isLegacyCheckupPage(): bool
    {
        return in_array($this->slug, self::LEGACY_CHECKUP_SLUGS, true);
    }
}
