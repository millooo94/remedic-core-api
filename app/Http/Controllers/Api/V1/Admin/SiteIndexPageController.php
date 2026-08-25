<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Services\CheckupPublicContentService;
use App\Services\MedicalAreaPublicService;
use App\Services\SiteIndexPageInitializer;
use App\Support\Media\PublicMediaUrl;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;

class SiteIndexPageController extends Controller
{
    public function __construct(private readonly MedicalAreaPublicService $areas, private readonly CheckupPublicContentService $checkups) {}

    public function index(SiteIndexPageInitializer $initializer)
    {
        $initializer->initialize();

        return response()->json(['data' => SiteIndexPage::query()->orderBy('id')->get()->map(fn ($p) => $this->data($p))->all()]);
    }

    public function update(Request $request, SiteIndexPage $siteIndexPage)
    {
        abort_unless(SiteIndexPageRegistry::contains($siteIndexPage->internal_key), 404);

        $allowed = ['title', 'content', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots', 'is_active', 'published_at'];
        $rules = ['title' => 'required|string|max:255', 'content' => 'required|array', 'seo_title' => 'nullable|string|max:255', 'seo_description' => 'nullable|string', 'seo_h1' => 'nullable|string|max:255', 'canonical_url' => 'nullable|string|max:255', 'robots' => 'nullable|string|max:255', 'is_active' => 'sometimes|boolean', 'published_at' => 'nullable|date'];
        foreach (SiteIndexPageRegistry::contentKeys($siteIndexPage->internal_key) as $key) {
            $rules["content.{$key}"] = ['required', 'string', 'max:5000'];
        }
        $data = $request->validate($rules);
        $siteIndexPage->fill(array_intersect_key($data, array_flip($allowed)))->save();

        return response()->json(['data' => $this->data($siteIndexPage)]);
    }

    private function data(SiteIndexPage $p): array
    {
        return ['id' => $p->id, 'internal_key' => $p->internal_key, 'title' => $p->title, 'slug' => $p->slug, 'content' => $this->content($p), 'seo_title' => $p->seo_title, 'seo_description' => $p->seo_description, 'seo_h1' => $p->seo_h1, 'canonical_url' => $p->canonical_url, 'robots' => $p->robots, 'is_active' => $p->is_active, 'published_at' => $p->published_at?->toIso8601String(), 'publication_state' => $p->publicationState()->value, 'preview' => $this->preview($p)];
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

    private function preview(SiteIndexPage $page): array
    {
        $request = request();
        if ($page->internal_key === 'medical_areas_index') {
            return ['items' => $this->areas->query()->get()->map(fn ($area) => [...$this->areas->listItem($area, $request), 'is_public' => true])->all()];
        }
        if ($page->internal_key === 'checkups_index') {
            return ['items' => $this->checkups->query()->limit(6)->get()->map(fn ($checkup) => [...$this->checkups->listItem($checkup, $request), 'is_public' => true])->all()];
        }
        $profiles = ProfessionalPublicProfile::query()->effectivelyVisible()->with(['professional.specializations.webProfile'])->orderBy('sort_order')->orderBy('id')->get();
        $items = $profiles->map(function ($profile) use ($request) {
            $professional = $profile->professional;
            $areas = $professional->specializations->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX]);
            $primary = $areas->first();

            return ['id' => $professional->id, 'name' => trim(($professional->honorific_prefix ? $professional->honorific_prefix.' ' : '').$professional->full_name), 'avatar_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request), 'role_label' => $profile->title_prefix, 'primary_area' => $primary?->name, 'tags' => $areas->take(2)->pluck('name')->values()->all(), 'is_public' => true];
        });

        return ['items' => $items->all(), 'available_areas' => $profiles->flatMap(fn ($profile) => $profile->professional->specializations)->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->unique('id')->sortBy('name')->map(fn ($area) => ['name' => $area->name, 'public_slug' => $area->webProfile->slug])->values()->all()];
    }
}
