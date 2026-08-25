<?php

namespace App\Services;

use App\Models\ProfessionalPublicProfile;
use App\Models\Service;
use App\Models\SiteIndexPage;
use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;

class SiteIndexProjectionService
{
    public function diagnostics(SiteIndexPage $page, Request $request): array
    {
        $items = $this->services('is_diagnostic', $request, $request->query('q'), $request->query('filter'));

        return ['items' => $items, 'result_count' => count($items), 'available_filters' => $this->filters('is_diagnostic')];
    }

    public function aesthetics(SiteIndexPage $page, Request $request): array
    {
        $category = trim((string) $request->query('filter')) ?: null;
        $items = $this->services('is_aesthetic_medicine', $request, null, null, $category, $page);
        $configuration = $page->configuration ?? [];
        $team = $this->featuredTeam($configuration['featured_professional_ids'] ?? [], $request);

        return ['items' => $items, 'result_count' => count($items), 'available_filters' => collect(SiteIndexPageRegistry::AESTHETIC_CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => data_get(collect($configuration['improvement_areas'] ?? [])->firstWhere('key', $key), 'label', $label)])->values()->all(), 'improvement_areas' => $configuration['improvement_areas'] ?? [], 'evaluation_steps' => $configuration['evaluation_steps'] ?? [], 'approach_principles' => $configuration['approach_principles'] ?? [], 'featured_team' => $team, 'faqs' => $page->faqs()->active()->ordered()->get()->map(fn ($faq) => ['id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer, 'is_structured_data' => (bool) $faq->is_structured_data])->all()];
    }

    public function adminAesthetics(SiteIndexPage $page, Request $request): array
    {
        $data = $this->aesthetics($page, $request);
        $data['unclassified_items'] = $this->services('is_aesthetic_medicine', $request, null, null, '__unclassified__', $page);
        $data['available_professionals'] = $this->availableProfessionals($request);

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function services(string $flag, Request $request, ?string $term = null, ?string $filter = null, ?string $aestheticCategory = null, ?SiteIndexPage $page = null): array
    {
        $query = Service::query()->effectivelyVisible()->with(['webProfile', 'specializations.webProfile'])->whereHas('webProfile', fn ($profile) => $profile->where($flag, true));
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

        return $query->orderBy('display_name')->get()->map(function (Service $service) use ($request): array {
            $profile = $service->webProfile;
            $primary = app(PrimarySpecializationResolver::class)->resolve($service);
            $category = $profile->aesthetic_category;

            return ['id' => $service->id, 'public_slug' => $profile->public_slug, 'href' => '/prestazioni/'.$profile->public_slug, 'image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request), 'name' => $service->publicLabel(), 'short_description' => $profile->short_description ?: '', 'area' => $primary ? ['name' => $primary->name, 'public_slug' => $primary->webProfile?->slug, 'href' => '/aree-mediche/'.$primary->webProfile?->slug] : null, 'category_label' => $category ? SiteIndexPageRegistry::AESTHETIC_CATEGORIES[$category] : ($primary?->name), 'aesthetic_category' => $category, 'is_public' => true];
        })->all();
    }

    private function filters(string $flag): array
    {
        return Service::query()->effectivelyVisible()->with('specializations.webProfile')->whereHas('webProfile', fn ($profile) => $profile->where($flag, true))->get()->flatMap(fn ($service) => $service->specializations)->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->unique('id')->sortBy('name')->map(fn ($area) => ['key' => $area->webProfile->slug, 'label' => $area->name])->values()->all();
    }

    private function availableProfessionals(Request $request): array
    {
        return ProfessionalPublicProfile::query()->effectivelyVisible()->with('professional.specializations.webProfile')->orderBy('sort_order')->get()->map(fn ($profile) => $this->professional($profile, $request))->all();
    }

    private function featuredTeam(array $ids, Request $request): array
    {
        $profiles = ProfessionalPublicProfile::query()->effectivelyVisible()->with('professional.specializations.webProfile')->whereIn('professional_id', $ids)->get()->keyBy('professional_id');

        return collect($ids)->map(fn ($id) => $profiles->get($id))->filter()->map(fn ($profile) => $this->professional($profile, $request))->values()->all();
    }

    private function professional(ProfessionalPublicProfile $profile, Request $request): array
    {
        $professional = $profile->professional;
        $areas = $professional->specializations->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX]);
        $name = trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name])));

        return ['id' => $professional->id, 'public_slug' => $profile->slug, 'href' => '/equipe/'.$profile->slug, 'full_name' => $name, 'name' => $name, 'avatar_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request), 'role_label' => $profile->title_prefix, 'tags' => $areas->pluck('name')->values()->all(), 'is_public' => true];
    }
}
