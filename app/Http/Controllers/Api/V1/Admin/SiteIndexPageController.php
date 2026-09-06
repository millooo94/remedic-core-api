<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Services\CheckupPublicContentService;
use App\Services\EditorialIndexProjectionService;
use App\Services\MedicalAreaPublicService;
use App\Services\SiteIndexPageInitializer;
use App\Services\SiteIndexProjectionService;
use App\Support\Media\PublicMediaUrl;
use App\Support\SiteIndexes\SiteIndexPageRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class SiteIndexPageController extends Controller
{
    public function __construct(private readonly MedicalAreaPublicService $areas, private readonly CheckupPublicContentService $checkups, private readonly SiteIndexProjectionService $projections, private readonly EditorialIndexProjectionService $editorial) {}

    public function index(SiteIndexPageInitializer $initializer)
    {
        $initializer->initialize();

        return response()->json(['data' => SiteIndexPage::query()->orderBy('id')->get()->map(fn ($p) => $this->data($p))->all()]);
    }

    public function update(Request $request, SiteIndexPage $siteIndexPage)
    {
        abort_unless(SiteIndexPageRegistry::contains($siteIndexPage->internal_key), 404);

        $allowed = ['title', 'content', 'configuration', 'seo_title', 'seo_description', 'seo_h1', 'canonical_url', 'robots', 'twitter_title', 'twitter_description', 'is_active'];
        $rules = ['title' => 'required|string|max:255', 'content' => 'required|array', 'configuration' => 'sometimes|array', 'seo_title' => 'nullable|string|max:255', 'seo_description' => 'nullable|string', 'seo_h1' => 'nullable|string|max:255', 'canonical_url' => 'nullable|string|max:255', 'robots' => 'nullable|string|max:255', 'twitter_title' => 'nullable|string|max:255', 'twitter_description' => 'nullable|string', 'is_active' => 'sometimes|boolean'];
        foreach (SiteIndexPageRegistry::contentKeys($siteIndexPage->internal_key) as $key) {
            $rules["content.{$key}"] = ['required', 'string', 'max:5000'];
        }
        if ($siteIndexPage->internal_key === 'medical_areas_index') {
            $rules += ['sections' => ['required', 'array', 'size:2'], 'sections.*.key' => ['required', 'string', 'in:hero,specialties_catalog'], 'sections.*.internal_title' => ['nullable', 'string', 'max:255'], 'sections.*.is_active' => ['required', 'boolean']];
        }
        $data = $request->validate($rules);
        if ($siteIndexPage->internal_key === 'medical_areas_index') {
            $this->syncMedicalAreasSections($siteIndexPage, $data['sections']);
            $data['content'] = Arr::only($data['content'], SiteIndexPageRegistry::contentKeys($siteIndexPage->internal_key));
        }
        if ($siteIndexPage->internal_key === 'aesthetic_medicine_index') {
            $configuration = $data['configuration'] ?? $siteIndexPage->configuration ?? [];
            $areas = $configuration['improvement_areas'] ?? [];
            abort_unless(collect($areas)->pluck('key')->all() === array_keys(SiteIndexPageRegistry::AESTHETIC_CATEGORIES), 422, 'Le quattro aree di miglioramento sono fisse.');
            abort_unless(count($configuration['evaluation_steps'] ?? []) === 3 && count($configuration['approach_principles'] ?? []) === 3, 422, 'Gli step e i principi sono strutturalmente tre.');
            $ids = array_map('intval', $configuration['featured_professional_ids'] ?? []);
            abort_if(count($ids) !== count(array_unique($ids)), 422, 'Un professionista può essere selezionato una sola volta.');
            $eligible = ProfessionalPublicProfile::query()->effectivelyVisible()->whereIn('professional_id', $ids)->count();
            abort_unless($eligible === count($ids), 422, 'Sono selezionabili solo professionisti pubblicabili.');
        }
        if (in_array($siteIndexPage->internal_key, ['news_index', 'health_pills_index'], true)) {
            $type = $siteIndexPage->internal_key === 'news_index' ? 'news' : 'health_pill';
            $configuration = $data['configuration'] ?? $siteIndexPage->configuration ?? [];
            foreach (['featured_post_id', 'secondary_post_id'] as $field) {
                if (! empty($configuration[$field]) && ! BlogPost::query()->whereKey($configuration[$field])->where('content_type', $type)->exists()) {
                    abort(422, 'Il contenuto selezionato non è compatibile con questa Index.');
                }
            }
            abort_if($type === 'news' && ! empty($configuration['featured_post_id']) && $configuration['featured_post_id'] === $configuration['secondary_post_id'], 422, 'Featured e secondaria devono essere diverse.');
        }
        $siteIndexPage->fill(array_intersect_key($data, array_flip($allowed)))->save();
        if (in_array($siteIndexPage->internal_key, ['aesthetic_medicine_index', 'conventions_network_index'], true) && array_key_exists('faqs', $request->all())) {
            $this->syncFaqs($siteIndexPage, $request->validate(['faqs' => ['array'], 'faqs.*.id' => ['nullable', 'integer'], 'faqs.*.question' => ['required', 'string'], 'faqs.*.answer' => ['required', 'string'], 'faqs.*.is_active' => ['required', 'boolean'], 'faqs.*.is_structured_data' => ['required', 'boolean']])['faqs'] ?? []);
        }
        if ($siteIndexPage->internal_key === 'conventions_network_index') {
            $this->syncConventionsShadow($siteIndexPage);
        }

        return response()->json(['data' => $this->data($siteIndexPage)]);
    }

    private function data(SiteIndexPage $p): array
    {
        return ['id' => $p->id, 'internal_key' => $p->internal_key, 'title' => $p->title, 'slug' => $p->slug, 'content' => $this->content($p), 'configuration' => $p->configuration ?? [], 'sections' => $p->internal_key === 'medical_areas_index' ? $p->sections()->get()->map(fn ($section) => ['key' => $section->key, 'internal_title' => $section->internal_title, 'is_active' => (bool) $section->is_active, 'sort_order' => $section->sort_order])->all() : [], 'media' => ['hero_video_url' => PublicMediaUrl::fromPublicDisk($p->hero_video_path, request()), 'hero_poster_url' => PublicMediaUrl::fromPublicDisk($p->hero_poster_path, request()), 'intro_split_image_url' => PublicMediaUrl::fromPublicDisk($p->intro_split_image_path, request()), 'og_image_url' => PublicMediaUrl::fromPublicDisk($p->og_image_path, request()), 'twitter_image_url' => PublicMediaUrl::fromPublicDisk($p->twitter_image_path, request())], 'faqs' => $p->faqs()->ordered()->get()->map(fn ($faq) => ['id' => $faq->id, 'question' => $faq->question, 'answer' => $faq->answer, 'is_active' => (bool) $faq->is_active, 'is_structured_data' => (bool) $faq->is_structured_data])->all(), 'seo_title' => $p->seo_title, 'seo_description' => $p->seo_description, 'seo_h1' => $p->seo_h1, 'canonical_url' => $p->canonical_url, 'robots' => $p->robots, 'twitter_title' => $p->twitter_title, 'twitter_description' => $p->twitter_description, 'is_active' => $p->is_active, 'preview' => $this->preview($p)];
    }

    private function content(SiteIndexPage $page): array
    {
        $content = $page->content ?? [];
        if ($page->internal_key === 'medical_areas_index') {
            return Arr::only($content, SiteIndexPageRegistry::contentKeys($page->internal_key));
        }
        if ($page->internal_key === 'checkups_index') {
            $content['final_cta_eyebrow'] ??= $content['final_eyebrow'] ?? '';
            $content['final_cta_title'] ??= $content['final_title'] ?? '';
            $content['final_cta_body'] ??= $content['final_body'] ?? '';
            unset($content['final_eyebrow'], $content['final_title'], $content['final_body']);
        }

        return $content;
    }

    /** @param list<array{key:string,internal_title?:string,is_active:bool}> $sections */
    private function syncMedicalAreasSections(SiteIndexPage $page, array $sections): void
    {
        $keys = collect($sections)->pluck('key')->all();
        abort_unless(count(array_unique($keys)) === 2 && collect($keys)->sort()->values()->all() === ['hero', 'specialties_catalog'], 422, 'Le sezioni dell’indice Aree mediche non sono valide.');

        foreach ($sections as $sortOrder => $input) {
            $section = $page->sections()->where('key', $input['key'])->firstOrFail();
            $section->fill([
                'is_active' => $input['is_active'],
                'sort_order' => $sortOrder,
                'internal_title' => $input['key'] === 'hero' ? trim((string) ($input['internal_title'] ?? '')) ?: 'Hero / introduzione' : 'Elenco specialità',
            ])->save();
        }
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
        if ($page->internal_key === 'diagnostics_index') {
            return $this->projections->diagnostics($page, request());
        }
        if ($page->internal_key === 'aesthetic_medicine_index') {
            return $this->projections->adminAesthetics($page, request());
        }
        if (in_array($page->internal_key, ['news_index', 'health_pills_index'], true)) {
            return $this->editorial->admin($page, request());
        }
        $profiles = ProfessionalPublicProfile::query()->effectivelyVisible()->with(['professional.specializations.webProfile'])->orderBy('id')->get();
        $items = $profiles->map(function ($profile) use ($request) {
            $professional = $profile->professional;
            $areas = $professional->specializations->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX]);
            $primary = $areas->first();

            return ['id' => $professional->id, 'name' => trim(($professional->honorific_prefix ? $professional->honorific_prefix.' ' : '').$professional->full_name), 'avatar_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request), 'role_label' => $profile->title_prefix, 'primary_area' => $primary?->name, 'tags' => $areas->take(2)->pluck('name')->values()->all(), 'is_public' => true];
        });

        return ['items' => $items->all(), 'available_areas' => $profiles->flatMap(fn ($profile) => $profile->professional->specializations)->filter(fn ($area) => $area->is_active && $area->webProfile?->is_web_enabled)->unique('id')->sortBy('name')->map(fn ($area) => ['name' => $area->name, 'public_slug' => $area->webProfile->slug])->values()->all()];
    }

    private function syncFaqs(SiteIndexPage $page, array $faqs): void
    {
        $ids = collect($faqs)->pluck('id')->filter()->map(fn ($id) => (int) $id);
        $page->faqs()->whereNotIn('id', $ids)->delete();
        foreach (array_values($faqs) as $order => $faq) {
            $model = ! empty($faq['id']) ? $page->faqs()->whereKey($faq['id'])->firstOrFail() : $page->faqs()->make();
            $model->fill(['question' => $faq['question'], 'answer' => $faq['answer'], 'is_active' => $faq['is_active'], 'is_structured_data' => $faq['is_structured_data'], 'sort_order' => $order]);
            $model->save();
        }
    }

    /** Keep the old Page only as a public-route compatibility projection. */
    private function syncConventionsShadow(SiteIndexPage $index): void
    {
        $legacy = Page::query()->with(['sections', 'faqs'])->firstOrCreate(
            ['internal_key' => Page::CONVENTIONS_NETWORK_INTERNAL_KEY],
            ['title' => $index->title, 'slug' => 'convenzioni-e-network']
        );
        $legacy->fill([
            'title' => $index->title, 'slug' => 'convenzioni-e-network', 'seo_title' => $index->seo_title,
            'seo_description' => $index->seo_description, 'seo_h1' => $index->seo_h1, 'robots' => $index->robots,
            'og_image_path' => $index->og_image_path, 'twitter_title' => $index->twitter_title, 'twitter_description' => $index->twitter_description, 'twitter_image_path' => $index->twitter_image_path,
            'is_active' => $index->is_active,
        ])->save();
        $values = $index->content ?? [];
        $extras = data_get($index->configuration, 'section_extras', []);
        $definitions = [
            'hero' => ['title' => 'hero_title', 'content' => 'hero_body', 'eyebrow' => 'hero_eyebrow'],
            'access_process' => ['title' => 'access_title', 'content' => 'access_body'],
            'conventions_catalog' => ['title' => 'catalog_title', 'content' => 'catalog_body'],
            'contact_cta' => ['title' => 'contact_title', 'content' => 'contact_body'],
        ];
        foreach (array_keys($definitions) as $sortOrder => $key) {
            $definition = $definitions[$key];
            $section = $legacy->sections()->firstOrNew(['key' => $key]);
            $extra = is_array($extras[$key] ?? null) ? $extras[$key] : ($section->extra_json ?? []);
            if (isset($definition['eyebrow'])) {
                $extra['eyebrow'] = $values[$definition['eyebrow']] ?? '';
            }
            $section->fill(['title' => $values[$definition['title']] ?? $section->title, 'content' => $values[$definition['content']] ?? $section->content, 'extra_json' => $extra, 'sort_order' => $sortOrder, 'is_active' => true])->save();
        }
        $legacy->faqs()->delete();
        foreach ($index->faqs()->ordered()->get() as $faq) {
            $legacy->faqs()->create(['question' => $faq->question, 'answer' => $faq->answer, 'sort_order' => $faq->sort_order, 'is_active' => $faq->is_active, 'is_structured_data' => $faq->is_structured_data]);
        }
    }
}
