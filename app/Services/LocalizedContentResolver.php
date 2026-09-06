<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\CheckupWebProfile;
use App\Models\ContentTranslation;
use App\Models\ServiceWebProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Shared effective-visibility and projection semantics for editorial translations. */
class LocalizedContentResolver
{
    public function publicTranslations(Builder $query, SupportedLocale $locale): Builder
    {
        // Italian is the source locale. Records created after the one-off backfill
        // remain compatible while their authoritative translation is initialized.
        if ($locale === SupportedLocale::IT) {
            return $query;
        }

        return $query->whereHas('translations', function (Builder $translations) use ($locale): void {
            $translations->where('locale', $locale->value)
                ->whereNotNull('title')->where('title', '<>', '')
                ->whereNotNull('slug')->where('slug', '<>', '');
        });
    }

    public function translation(Model $owner, SupportedLocale $locale): ?ContentTranslation
    {
        $translation = method_exists($owner, 'translationFor') ? $owner->translationFor($locale) : null;

        return $translation?->isPubliclyAvailable() ? $translation : null;
    }

    /** A cloned presentation model lets legacy compatibility fields remain read-only. */
    public function project(Model $owner, SupportedLocale $locale): ?Model
    {
        $translation = $this->translation($owner, $locale);
        if ($translation === null && $locale === SupportedLocale::IT) {
            return clone $owner;
        }
        if ($translation === null) {
            return null;
        }

        $copy = clone $owner;
        $map = ['slug' => $owner instanceof ServiceWebProfile || $owner instanceof CheckupWebProfile ? 'public_slug' : 'slug', 'public_slug' => 'slug', 'short_bio' => 'short_description', 'bio_content' => 'body'];
        foreach (['title', 'slug', 'excerpt', 'intro_text', 'short_description', 'subtitle', 'category_label', 'body', 'custom_html', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description', 'twitter_title', 'twitter_description'] as $field) {
            if ($translation->{$field} !== null) {
                $copy->setAttribute($map[$field] ?? $field, $translation->{$field});
            }
        }
        if ($locale !== SupportedLocale::IT) {
            foreach (['sections' => ['title', 'subtitle', 'content'], 'faqs' => ['question', 'answer']] as $relation => $fields) {
                if (! $copy->relationLoaded($relation)) {
                    continue;
                }
                $copy->setRelation($relation, $copy->getRelation($relation)->map(function (Model $item) use ($locale, $fields): Model {
                    $item = clone $item;
                    $localized = $item->relationLoaded('translations') ? $item->translations->firstWhere('locale', $locale) : null;
                    foreach ($fields as $field) {
                        if ($localized?->{$field} !== null) {
                            $item->setAttribute($field, $localized->{$field});
                        }
                    }

                    return $item;
                }));
            }
        }
        $copy->setRelation('localizedTranslation', $translation);

        return $copy;
    }

    public function availableLocales(Model $owner): array
    {
        $translations = $owner->relationLoaded('translations')
            ? $owner->translations
            : $owner->translations()->get();
        $locales = $translations->filter(fn (ContentTranslation $translation) => $translation->isPubliclyAvailable())
            ->map(fn (ContentTranslation $translation) => $translation->locale->value)->values()->all();

        $locales = array_values(array_unique(['it', ...$locales]));
        $order = array_flip(array_map(fn (SupportedLocale $locale) => $locale->value, SupportedLocale::cases()));
        usort($locales, fn (string $left, string $right): int => $order[$left] <=> $order[$right]);

        return $locales;
    }

    /** Sections and FAQs are structural masters: every active item needs a local copy. */
    public function hasCompleteStructure(Model $owner, SupportedLocale $locale): bool
    {
        if ($locale === SupportedLocale::IT) {
            return true;
        }
        foreach (['sections', 'faqs'] as $relation) {
            if (! $owner->relationLoaded($relation)) {
                continue;
            }
            /** @var Collection $items */
            $items = $owner->getRelation($relation);
            foreach ($items as $item) {
                $translation = $item->relationLoaded('translations')
                    ? $item->translations->firstWhere('locale', $locale)
                    : $item->translations()->where('locale', $locale->value)->first();
                if ($translation === null || ! filled($translation->title ?? $translation->question) || ! filled($translation->content ?? $translation->answer)) {
                    return false;
                }
            }
        }

        return true;
    }
}
