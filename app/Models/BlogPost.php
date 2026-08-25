<?php

namespace App\Models;

use App\Enums\PublicationState;
use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BlogPost extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'title',
        'slug',
        'subtitle',
        'category_label',
        'content_type',
        'editorial_category',
        'excerpt',
        'intro_text',
        'cover_image',
        'author_name',
        'reviewer_name',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'robots' => RobotsValue::class,
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

    public function scopeHealthPills(Builder $query): Builder
    {
        return $query->where('content_type', 'health_pill');
    }

    public const NEWS_CATEGORIES = ['services' => 'Servizi', 'professionals' => 'Professionisti', 'initiatives' => 'Iniziative', 'technology' => 'Tecnologia', 'network' => 'Network', 'center' => 'Centro'];

    public const HEALTH_PILL_CATEGORIES = ['nutrition' => 'Nutrizione', 'cardiology' => 'Cardiologia', 'wellness' => 'Benessere', 'prevention' => 'Prevenzione', 'respiration' => 'Respirazione'];

    public static function editorialCategories(?string $contentType): array
    {
        return match ($contentType) {
            'news' => self::NEWS_CATEGORIES,
            'health_pill' => self::HEALTH_PILL_CATEGORIES,
            default => [],
        };
    }

    public function canonicalHref(): string
    {
        return match ($this->content_type) {
            'news' => '/news/'.$this->slug,
            'health_pill' => '/pillole-di-salute/'.$this->slug,
            default => '/blog/'.$this->slug,
        };
    }

    public function relatedServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'blog_post_services')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('services.id');
    }

    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blog_post_related_posts', 'blog_post_id', 'related_blog_post_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('blog_posts.id');
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
}
