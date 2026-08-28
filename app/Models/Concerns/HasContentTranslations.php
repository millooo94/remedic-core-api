<?php

namespace App\Models\Concerns;

use App\Enums\SupportedLocale;
use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\ConsentCategory;
use App\Models\ContentTranslation;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\ServiceWebProfile;
use App\Models\SpecializationWebProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasContentTranslations
{
    /**
     * The legacy Italian columns remain a compatibility projection for the
     * existing backoffice.  Keeping its translation in sync here makes the
     * translation table authoritative for publication and review decisions
     * even when an older editor saves the source record directly.
     */
    public static function bootHasContentTranslations(): void
    {
        static::saved(function (Model $owner): void {
            $fields = self::localizedSourceFields($owner);
            if ($fields === []) {
                return;
            }

            $translation = $owner->translations()->where('locale', SupportedLocale::IT->value)->first();
            $changed = $translation === null || $owner->wasRecentlyCreated || collect($fields)->contains(fn (string $field): bool => $owner->wasChanged($field));
            if (! $changed) {
                return;
            }

            $values = self::sourceValues($owner, $fields);
            $revision = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $owner->translations()->updateOrCreate(
                ['locale' => SupportedLocale::IT->value],
                [...$values, 'publication_state' => 'published', 'source_revision' => $revision, 'reviewed_source_revision' => $revision]
            );
            $owner->translations()->where('locale', '!=', SupportedLocale::IT->value)->update(['source_revision' => $revision]);
        });
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(ContentTranslation::class, 'translatable');
    }

    public function translationFor(SupportedLocale $locale): ?ContentTranslation
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->first(fn (ContentTranslation $translation) => $translation->locale === $locale);
        }

        return $this->translations()->locale($locale)->first();
    }

    private static function localizedSourceFields(Model $owner): array
    {
        return match ($owner::class) {
            Page::class => ['title', 'slug', 'excerpt', 'intro_text', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            SpecializationWebProfile::class => ['slug', 'short_description', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            ServiceWebProfile::class => ['public_slug', 'short_description', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            ProfessionalPublicProfile::class => ['slug', 'short_bio', 'bio_content', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            CheckupWebProfile::class => ['public_slug', 'short_description', 'category_label', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            ConsentCategory::class => ['name', 'description'],
            BlogPost::class => ['title', 'slug', 'subtitle', 'category_label', 'excerpt', 'intro_text', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'],
            default => [],
        };
    }

    private static function sourceValues(Model $owner, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[match ($field) {
                'public_slug' => 'slug',
                'short_bio' => 'short_description',
                'bio_content' => 'body',
                'name' => 'label',
                default => $field,
            }] = $owner->getAttribute($field);
        }

        if (blank($values['title'] ?? null)) {
            $values['title'] = match ($owner::class) {
                SpecializationWebProfile::class => $owner->specialization?->name,
                ServiceWebProfile::class => $owner->service?->display_name,
                ProfessionalPublicProfile::class => $owner->professional?->display_name ?? $owner->professional?->full_name,
                CheckupWebProfile::class => $owner->checkup?->display_name,
                default => null,
            };
        }

        return $values;
    }
}
