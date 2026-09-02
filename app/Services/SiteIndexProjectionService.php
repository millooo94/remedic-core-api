<?php

namespace App\Services;

use App\Enums\ServiceClassification;
use App\Models\ProfessionalPublicProfile;
use App\Models\Service;
use App\Models\SiteIndexPage;
use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;

class SiteIndexProjectionService
{
    public function __construct(
        private readonly ServicePublicContentService $services,
        private readonly PublicLocaleResolver $locales,
        private readonly LocalizedContentResolver $localized,
        private readonly LocalizedRouteRegistry $routes,
    ) {}

    public function diagnostics(SiteIndexPage $page, Request $request): array
    {
        $items = $this->services(ServiceClassification::Diagnostic, $request, $request->query('q'), $request->query('filter'));

        return ['items' => $items, 'result_count' => count($items), 'available_filters' => $this->filters(ServiceClassification::Diagnostic, $request)];
    }

    public function aesthetics(SiteIndexPage $page, Request $request): array
    {
        $category = trim((string) $request->query('filter')) ?: null;
        $items = $this->services(ServiceClassification::AestheticMedicine, $request, null, null, $category, $page);
        $configuration = $page->configuration ?? [];
        $team = $this->featuredTeam($configuration['featured_professional_ids'] ?? [], $request);

        return ['items' => $items, 'result_count' => count($items), 'available_filters' => collect(SiteIndexPageRegistry::AESTHETIC_CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => data_get(collect($configuration['improvement_areas'] ?? [])->firstWhere('key', $key), 'label', $label)])->values()->all(), 'improvement_areas' => $configuration['improvement_areas'] ?? [], 'evaluation_steps' => $configuration['evaluation_steps'] ?? [], 'approach_principles' => $configuration['approach_principles'] ?? [], 'featured_team' => $team, 'faqs' => $page->faqs()->active()->orderBy('id')->get()->map(fn ($faq) => ['question' => $faq->question, 'answer' => $faq->answer, 'is_structured_data' => (bool) $faq->is_structured_data])->all()];
    }

    public function adminAesthetics(SiteIndexPage $page, Request $request): array
    {
        $data = $this->aesthetics($page, $request);
        $data['unclassified_items'] = $this->services(ServiceClassification::AestheticMedicine, $request, null, null, '__unclassified__', $page);
        $data['available_professionals'] = $this->availableProfessionals($request);

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function services(ServiceClassification $classification, Request $request, ?string $term = null, ?string $filter = null, ?string $aestheticCategory = null, ?SiteIndexPage $page = null): array
    {
        $locale = $this->locales->resolve($request);
        $query = $this->services->query()->where('classification', $classification->value);
        if ($aestheticCategory === '__unclassified__') {
            $query->whereHas('webProfile', fn ($profile) => $profile->whereNull('aesthetic_category'));
        } elseif ($aestheticCategory !== null) {
            $query->whereHas('webProfile', fn ($profile) => $profile->where('aesthetic_category', $aestheticCategory));
        }
        if ($filter) {
            $query->whereHas('specializations.webProfile', fn ($profile) => $profile->where('slug', $filter)->where('is_web_enabled', true));
        }
        if ($term = trim((string) $term)) {
            $query->where(fn ($nested) => $nested->where('display_name', 'like', "%{$term}%")->orWhereHas('webProfile', fn ($profile) => $profile->where('short_description', 'like', "%{$term}%"))->orWhereHas('specializations', fn ($area) => $area->where('name', 'like', "%{$term}%")));
        }

        return $query->orderBy('display_name')->get()->map(function (Service $service) use ($request, $locale): array {
            $profile = $this->localized->project($service->webProfile, $locale) ?? abort(404);
            $primary = app(PrimarySpecializationResolver::class)->resolve($service);
            $primaryProfile = $primary?->webProfile === null ? null : $this->localized->project($primary->webProfile, $locale);
            $category = $profile->aesthetic_category;

            return ['public_slug' => $profile->public_slug, 'href' => $this->routes->path('services', $locale, $profile->public_slug), 'image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request), 'name' => $profile->localizedTranslation?->title ?: $service->publicLabel(), 'short_description' => $profile->short_description ?: '', 'area' => $primaryProfile ? ['name' => $primaryProfile->localizedTranslation?->title ?: $primary->name, 'public_slug' => $primaryProfile->slug, 'href' => $this->routes->path('medical_areas', $locale, $primaryProfile->slug)] : null, 'category_label' => $category ? SiteIndexPageRegistry::AESTHETIC_CATEGORIES[$category] : ($primary?->name), 'aesthetic_category' => $category, 'is_public' => true];
        })->all();
    }

    private function filters(ServiceClassification $classification, Request $request): array
    {
        $locale = $this->locales->resolve($request);

        return $this->services->query()->where('classification', $classification->value)->get()->flatMap(fn ($service) => $service->specializations)->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->map(function ($area) use ($locale) {
            $profile = $this->localized->project($area->webProfile, $locale);

            return $profile === null ? null : ['key' => $profile->slug, 'label' => $profile->localizedTranslation?->title ?: $area->name];
        })->filter()->unique('key')->sortBy('label')->values()->all();
    }

    private function availableProfessionals(Request $request): array
    {
        return $this->professionalQuery($request)->orderBy('id')->get()->map(fn ($profile) => $this->professional($profile, $request))->filter()->values()->all();
    }

    private function featuredTeam(array $ids, Request $request): array
    {
        $profiles = $this->professionalQuery($request)->whereIn('professional_id', $ids)->get()->keyBy('professional_id');

        return collect($ids)->map(fn ($id) => $profiles->get($id))->filter()->map(fn ($profile) => $this->professional($profile, $request))->values()->all();
    }

    private function professional(ProfessionalPublicProfile $profile, Request $request): ?array
    {
        $locale = $this->locales->resolve($request);
        $profile = $this->localized->project($profile, $locale);
        if ($profile === null) {
            return null;
        }
        $professional = $profile->professional;
        $areas = $professional->specializations->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX]);
        $name = trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name])));

        return ['public_slug' => $profile->slug, 'href' => $this->routes->path('team', $locale, $profile->slug), 'full_name' => $name, 'name' => $name, 'avatar_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request), 'role_label' => $profile->title_prefix, 'tags' => $areas->map(function ($area) use ($locale) {
            $areaProfile = $this->localized->project($area->webProfile, $locale);

            return $areaProfile?->localizedTranslation?->title ?: $area->name;
        })->values()->all(), 'is_public' => true];
    }

    private function professionalQuery(Request $request)
    {
        $locale = $this->locales->resolve($request);
        $query = ProfessionalPublicProfile::query()->effectivelyVisible()->with(['translations', 'professional.specializations.webProfile.translations']);

        return $this->localized->publicTranslations($query, $locale);
    }
}
