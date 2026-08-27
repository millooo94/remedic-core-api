<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\Professional;
use App\Models\Specialization;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Canonical embedded medical-area projection for public professional consumers. */
class ProfessionalPublicAreaProjection
{
    public function __construct(
        private readonly LocalizedContentResolver $localized,
        private readonly LocalizedRouteRegistry $routes,
    ) {}

    /**
     * @return Collection<int, array{name: string, slug: string, href: string, icon_url: ?string, is_primary: bool}>
     */
    public function areas(Professional $professional, SupportedLocale $locale, Request $request): Collection
    {
        return $professional->specializations
            ->filter(fn (Specialization $area): bool => $area->isEffectivelyVisible())
            ->sortBy(fn (Specialization $area): array => [
                ($area->pivot?->is_primary ?? false) ? 0 : 1,
                $area->pivot?->sort_order ?? PHP_INT_MAX,
                $area->id,
            ])
            ->map(function (Specialization $area) use ($locale, $request): ?array {
                $profile = $area->webProfile === null
                    ? null
                    : $this->localized->project($area->webProfile, $locale);

                if ($profile === null) {
                    return null;
                }

                return [
                    'name' => $profile->localizedTranslation?->title ?: $area->name,
                    'slug' => $profile->slug,
                    'href' => $this->routes->path('medical_areas', $locale, $profile->slug),
                    'icon_url' => PublicMediaUrl::fromPublicDisk($area->icon_path, $request),
                    'is_primary' => (bool) ($area->pivot?->is_primary ?? false),
                ];
            })
            ->filter()
            ->values();
    }
}
