<?php

namespace App\Models\Concerns;

use App\Enums\SupportedLocale;
use App\Models\SiteIndexPage;
use App\Models\SiteNavigation;
use App\Models\SitePopup;
use Illuminate\Database\Eloquent\Model;

/** Keeps legacy source fields and singleton translations revision-safe. */
trait SynchronizesLocalizedSingleton
{
    public static function bootSynchronizesLocalizedSingleton(): void
    {
        static::saved(function (Model $owner): void {
            $fields = self::singletonLocalizedFields($owner);
            if ($fields === [] || (! $owner->wasRecentlyCreated && ! collect($fields)->contains(fn (string $field): bool => $owner->wasChanged($field)))) {
                return;
            }

            $values = collect($fields)->mapWithKeys(fn (string $field) => [$field => $owner->getAttribute($field)])->all();
            $revision = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
            $translation = $owner->translations()->where('locale', SupportedLocale::IT->value)->first();
            if ($translation !== null) {
                $translation->fill([...$values, 'source_revision' => $revision, 'reviewed_source_revision' => $revision])->save();
                $owner->translations()->where('locale', '!=', SupportedLocale::IT->value)->update(['source_revision' => $revision]);
            }
        });
    }

    private static function singletonLocalizedFields(Model $owner): array
    {
        return match ($owner::class) {
            SiteIndexPage::class => ['title', 'slug', 'content', 'seo_title', 'seo_description', 'seo_h1'],
            SiteNavigation::class => ['configuration'],
            SitePopup::class => ['eyebrow', 'title', 'body', 'primary_cta_label', 'secondary_cta_label'],
            default => [],
        };
    }
}
