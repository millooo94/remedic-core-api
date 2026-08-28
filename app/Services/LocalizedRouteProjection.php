<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\Page;
use App\Models\SiteIndexPage;
use Illuminate\Database\Eloquent\Model;

/**
 * Projects canonical alternate destinations for one public resource.
 *
 * The source model and its translations identify the same entity.  Consumers
 * receive only routes for translations that are already publicly available.
 */
class LocalizedRouteProjection
{
    public function __construct(
        private readonly LocalizedContentResolver $localized,
        private readonly LocalizedRouteRegistry $routes,
    ) {}

    /** @param callable(Model): string $slug */
    public function content(Model $owner, string $route, callable $slug): array
    {
        return $this->fromLocales($this->localized->availableLocales($owner), function (SupportedLocale $locale) use ($owner, $route, $slug): ?string {
            $localized = $this->localized->project($owner, $locale);
            $localizedSlug = $localized === null ? null : $slug($localized);

            return filled($localizedSlug)
                ? $this->routes->path($route, $locale, $localizedSlug)
                : null;
        });
    }

    public function page(Page $page): array
    {
        return $this->fromLocales($this->localized->availableLocales($page), function (SupportedLocale $locale) use ($page): ?string {
            $localized = $this->localized->project($page, $locale);

            return $localized instanceof Page && filled($localized->slug)
                ? $this->routes->page($locale, $localized->slug)
                : null;
        });
    }

    public function home(Page $page): array
    {
        return $this->fromLocales(
            $this->localized->availableLocales($page),
            fn (SupportedLocale $locale): string => $this->routes->homepage($locale),
        );
    }

    public function siteIndexLocales(SiteIndexPage $page): array
    {
        $available = $page->relationLoaded('translations')
            ? $page->translations
            : $page->translations()->get();
        $translated = $available->filter(fn ($translation): bool => $translation->isPubliclyAvailable())
            ->map(fn ($translation): string => $translation->locale->value)
            ->all();

        return $this->ordered([SupportedLocale::IT->value, ...$translated]);
    }

    public function siteIndex(SiteIndexPage $page, string $route): array
    {
        return $this->fromLocales(
            $this->siteIndexLocales($page),
            fn (SupportedLocale $locale): string => $this->routes->path($route, $locale),
        );
    }

    /** @param list<string> $locales @param callable(SupportedLocale): ?string $href */
    private function fromLocales(array $locales, callable $href): array
    {
        return collect($this->ordered($locales))
            ->map(function (string $locale) use ($href): ?array {
                $supported = SupportedLocale::from($locale);
                $path = $href($supported);

                return $path === null ? null : ['locale' => $supported->value, 'href' => $path];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @param list<string> $locales @return list<string> */
    private function ordered(array $locales): array
    {
        return collect(SupportedLocale::cases())
            ->map(fn (SupportedLocale $locale): string => $locale->value)
            ->filter(fn (string $locale): bool => in_array($locale, $locales, true))
            ->values()
            ->all();
    }
}
