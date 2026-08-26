<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Services\CheckupPublicContentService;
use App\Services\EditorialIndexProjectionService;
use App\Services\LocalizedRouteRegistry;
use App\Services\MedicalAreaPublicService;
use App\Services\PublicLocaleResolver;
use App\Services\PublicSeoResolver;
use App\Services\SiteIndexProjectionService;
use App\Support\Media\PublicMediaUrl;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;

class SiteIndexPageController extends Controller
{
    public function __construct(private MedicalAreaPublicService $areas, private CheckupPublicContentService $checkups, private SiteIndexProjectionService $projections, private EditorialIndexProjectionService $editorial, private PublicSeoResolver $seo, private PublicLocaleResolver $locales, private LocalizedRouteRegistry $routes) {}

    public function show(Request $request, string $key)
    {
        abort_unless(SiteIndexPageRegistry::contains($key), 404);
        $locale = $this->locales->resolve($request);

        $page = SiteIndexPage::query()->with('translations')->where('internal_key', $key)->active()->published()->firstOrFail();
        $translation = $page->translations->firstWhere('locale', $locale);
        abort_if($locale->value !== 'it' && ! $translation?->isPubliclyAvailable(), 404);
        if ($translation !== null) {
            $page->forceFill($translation->only(['title', 'slug', 'content', 'seo_title', 'seo_description', 'seo_h1']));
        }
        $projection = match ($key) {
            'medical_areas_index' => $this->areas->query()->when($request->filled('q'), fn ($q) => $q->whereHas('specialization', fn ($s) => $s->where('name', 'like', '%'.$request->q.'%')))->get()->map(fn ($area) => $this->areaItem($area, $request))->all(),
            'equipe_index' => $this->professionals($request),
            'checkups_index' => $this->checkups->query()->limit(6)->get()->map(fn ($checkup) => $this->checkups->indexItem($checkup, $request))->all(),
            'diagnostics_index' => $this->projections->diagnostics($page, $request),
            'aesthetic_medicine_index' => $this->projections->aesthetics($page, $request),
            'news_index', 'health_pills_index' => $this->editorial->public($page, $request),
        };
        $data = is_array($projection) && array_key_exists('items', $projection) ? $projection : ['items' => $projection, 'result_count' => count($projection)];
        $data += ['available_areas' => $key === 'equipe_index' ? $this->availableAreas() : [], 'final_cta' => $key === 'checkups_index' ? ['action' => 'contact'] : null];
        if ($key === 'diagnostics_index') {
            $data['catalog_anchor'] = 'catalogo';
            $data['hero_action'] = ['action' => 'anchor', 'target' => 'catalogo'];
            $data['contact_cta'] = ['action' => 'contact'];
        }
        if ($key === 'aesthetic_medicine_index') {
            $data['hero_actions'] = ['primary' => ['action' => 'anchor', 'target' => 'trattamenti'], 'secondary' => ['action' => 'booking']];
            $data['final_cta'] = ['action' => 'booking'];
        }

        $canonicalPath = $this->canonicalPath($key, $locale, $page->slug);

        return response()->json(['data' => ['locale' => $locale->value, 'internal_key' => $key, 'title' => $page->title, 'slug' => $page->slug, 'canonical_url' => $canonicalPath, 'available_locales' => $this->availableLocales($page), 'content' => $this->content($page), 'media' => $this->media($page, $request), 'seo' => [...$this->seo->resolve(['title' => $page->title, 'description' => $page->content['body'] ?? null, 'seo_title' => $page->seo_title, 'seo_description' => $page->seo_description, 'robots' => $page->robots, 'image_url' => PublicMediaUrl::fromPublicDisk($page->hero_poster_path ?: $page->intro_split_image_path, $request)], $canonicalPath, $request), 'h1' => $page->seo_h1], ...$data]]);
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

    private function media(SiteIndexPage $page, Request $request): array
    {
        return ['hero_video_url' => PublicMediaUrl::fromPublicDisk($page->hero_video_path, $request), 'hero_poster_url' => PublicMediaUrl::fromPublicDisk($page->hero_poster_path, $request), 'intro_split_image_url' => PublicMediaUrl::fromPublicDisk($page->intro_split_image_path, $request)];
    }

    private function canonicalPath(string $key, SupportedLocale $locale, string $slug): string
    {
        $route = match ($key) {
            'medical_areas_index' => 'medical_areas',
            'equipe_index' => 'team',
            'checkups_index' => 'checkups',
            'diagnostics_index' => 'diagnostics',
            'aesthetic_medicine_index' => 'aesthetic_medicine',
            'news_index' => 'news',
            'health_pills_index' => 'health_tips',
        };

        return $this->routes->path($route, $locale);
    }

    private function availableLocales(SiteIndexPage $page): array
    {
        return $page->translations->filter(fn ($translation): bool => $translation->isPubliclyAvailable())
            ->map(fn ($translation) => $translation->locale->value)
            ->sortBy(fn (string $locale): int => array_search($locale, ['it', 'en', 'es', 'fr'], true))
            ->values()
            ->all();
    }
}
