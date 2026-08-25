<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Services\CheckupPublicContentService;
use App\Services\MedicalAreaPublicService;
use App\Support\Media\PublicMediaUrl;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;

class SiteIndexPageController extends Controller
{
    public function __construct(private MedicalAreaPublicService $areas, private CheckupPublicContentService $checkups) {}

    public function show(Request $request, string $key)
    {
        abort_unless(SiteIndexPageRegistry::contains($key), 404);

        $page = SiteIndexPage::query()->where('internal_key', $key)->active()->published()->firstOrFail();
        $items = match ($key) {
            'medical_areas_index' => $this->areas->query()->when($request->filled('q'), fn ($q) => $q->whereHas('specialization', fn ($s) => $s->where('name', 'like', '%'.$request->q.'%')))->get()->map(fn ($area) => $this->areaItem($area, $request))->all(),
            'equipe_index' => $this->professionals($request),
            'checkups_index' => $this->checkups->query()->limit(6)->get()->map(fn ($checkup) => $this->checkups->indexItem($checkup, $request))->all(),
        };

        return response()->json(['data' => ['internal_key' => $key, 'canonical_url' => $page->canonical_url, 'content' => $this->content($page), 'seo' => ['title' => $page->seo_title, 'description' => $page->seo_description, 'h1' => $page->seo_h1, 'canonical_url' => $page->canonical_url, 'robots' => $page->robots], 'items' => $items, 'result_count' => count($items), 'available_areas' => $key === 'equipe_index' ? $this->availableAreas() : [], 'final_cta' => $key === 'checkups_index' ? ['action' => 'contact'] : null]]);
    }

    private function content(SiteIndexPage $page): array
    {
        $content = $page->content ?? [];
        if ($page->internal_key === 'checkups_index') {
            $content['final_cta_eyebrow'] ??= $content['final_eyebrow'] ?? '';
            $content['final_cta_title'] ??= $content['final_title'] ?? '';
            $content['final_cta_body'] ??= $content['final_body'] ?? '';
            unset($content['final_eyebrow'], $content['final_title'], $content['final_body']);
        }

        return $content;
    }

    private function areaItem($area, Request $request): array
    {
        return [...$this->areas->listItem($area, $request), 'id' => $area->specialization_id, 'public_slug' => $area->slug, 'href' => '/aree-mediche/'.$area->slug];
    }

    private function professionals(Request $request): array
    {
        $query = ProfessionalPublicProfile::query()->effectivelyVisible()->with(['professional.specializations.webProfile']);
        if ($area = trim((string) $request->query('area'))) {
            $query->whereHas('professional.specializations', fn ($q) => $q
                ->where('specializations.is_active', true)
                ->whereHas('webProfile', fn ($profile) => $profile->where('slug', $area)->where('is_web_enabled', true)));
        }
        if ($term = trim((string) $request->query('q'))) {
            $query->whereHas('professional', fn ($q) => $q->where('full_name', 'like', "%{$term}%")
                ->orWhereHas('specializations', fn ($s) => $s->where('specializations.is_active', true)
                    ->whereHas('webProfile', fn ($profile) => $profile->where('is_web_enabled', true))
                    ->where('name', 'like', "%{$term}%")));
        }

        return $query->orderBy('sort_order')->orderBy('id')->get()->map(function ($profile) use ($request) {
            $professional = $profile->professional;
            $areas = $professional->specializations->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX]);
            $primary = $areas->first();

            $fullName = trim(($professional->honorific_prefix ? $professional->honorific_prefix.' ' : '').$professional->full_name);

            return ['id' => $professional->id, 'public_slug' => $profile->slug, 'href' => '/equipe/'.$profile->slug, 'name' => $fullName, 'full_name' => $fullName, 'avatar_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request), 'role_label' => $profile->title_prefix, 'primary_area' => $primary ? ['name' => $primary->name, 'public_slug' => $primary->webProfile->slug, 'href' => '/aree-mediche/'.$primary->webProfile->slug] : null, 'tags' => $areas->take(2)->pluck('name')->values()->all()];
        })->all();
    }

    private function availableAreas(): array
    {
        return ProfessionalPublicProfile::query()->effectivelyVisible()->with('professional.specializations.webProfile')->get()->flatMap(fn ($profile) => $profile->professional->specializations)->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->unique('id')->sortBy('name')->map(fn ($area) => ['name' => $area->name, 'public_slug' => $area->webProfile->slug])->values()->all();
    }
}
