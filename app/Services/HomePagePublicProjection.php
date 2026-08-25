<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\ConventionPartner;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteSetting;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;

class HomePagePublicProjection
{
    public function __construct(private readonly MedicalAreaPublicService $areas, private readonly ServicePublicContentService $services, private readonly CheckupPublicContentService $checkups, private readonly ContactCenterDataResolver $center) {}

    /** @return array<string,mixed> */
    public function project(Page $page, Request $request): array
    {
        $sections = $page->sections()->active()->ordered()->get()->map(function ($section) use ($request): array {
            $data = $section->extra_json ?? [];
            $data = match ($section->key) {
                'medical_areas' => [...$data, 'items' => $this->areas->query()->limit($this->limit($data))->get()->map(fn ($item) => $this->areas->listItem($item, $request))->values()->all()],
                'professionals' => [...$data, 'items' => $this->professionals($request, $this->limit($data))],
                'diagnostics' => [...$data, 'items' => $this->services->query()->whereHas('webProfile', fn ($q) => $q->where('is_diagnostic', true))->limit($this->limit($data))->get()->map(fn ($item) => $this->services->listItem($item, $request))->values()->all()],
                'checkups' => [...$data, 'items' => $this->checkups->query()->limit(3)->get()->map(fn ($item) => $this->checkups->listItem($item, $request))->values()->all()],
                'aesthetic_medicine' => [...$data, 'items' => $this->services->query()->whereHas('webProfile', fn ($q) => $q->where('is_aesthetic_medicine', true))->limit($this->limit($data))->get()->map(fn ($item) => $this->services->listItem($item, $request))->values()->all()],
                'health_pills' => [...$data, 'items' => $this->posts($data)],
                'conventions' => [...$data, ...$this->partners($data, $request)],
                'faq' => [...$data, 'items' => $section->sectionable->faqs()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($faq) => ['question' => $faq->question, 'answer' => $faq->answer, 'is_structured_data' => (bool) $faq->is_structured_data])->all()],
                'contact' => [...$data, 'center' => $this->center->resolve(SiteSetting::current())],
                default => $data,
            };
            $data['media'] = $this->media($data, $request);
            if ($section->key === 'hero') {
                $data['booking_action'] = ['type' => 'booking'];
                $data['search_action'] = $this->indexAction('medical_areas_index');
            }
            if ($target = match ($section->key) {
                'medical_areas' => 'medical_areas_index',
                'professionals' => 'equipe_index',
                'checkups' => 'checkups_index',
                default => null,
            }) {
                $data['index_action'] = $this->indexAction($target);
            }
            if ($section->key === 'newsletter') {
                $data['component_type'] = 'newsletter_signup';
            }

            return ['key' => $section->key, 'order' => (int) $section->sort_order, 'data' => $data];
        })->values()->all();

        return ['slug' => $page->slug, 'canonical_url' => '/', 'sections' => $sections];
    }

    private function limit(array $data): int
    {
        return min(24, max(1, (int) ($data['max_items'] ?? 6)));
    }

    private function indexAction(string $key): array
    {
        $action = ['target' => $key];
        $page = SiteIndexPage::query()->where('internal_key', $key)->first();
        if ($page?->isPubliclyAvailable()) {
            $action['href'] = $page->canonical_url;
        }

        return $action;
    }

    private function media(array $data, Request $request): array
    {
        return collect($data['media'] ?? [])->map(fn ($m) => ['url' => PublicMediaUrl::fromPublicDisk($m['path'] ?? null, $request), 'alt' => $m['alt'] ?? null])->all();
    }

    private function professionals(Request $request, int $limit): array
    {
        return ProfessionalPublicProfile::query()->effectivelyVisible()->with(['professional.specializations'])->orderBy('sort_order')->orderBy('id')->limit($limit)->get()->map(function ($profile) use ($request) {
            $p = $profile->professional;
            $area = $p->specializations->sortBy(fn ($s) => [($s->pivot->is_primary ?? false) ? 0 : 1, $s->pivot->sort_order ?? 999])->first();

            return ['slug' => $profile->slug, 'href' => '/equipe/'.$profile->slug, 'name' => trim(($p->honorific_prefix ? $p->honorific_prefix.' ' : '').$p->full_name), 'short_bio' => $profile->short_bio ?: '', 'image_url' => PublicMediaUrl::fromPublicDisk($p->avatar_path, $request), 'primary_specialization' => $area?->name];
        })->all();
    }

    private function posts(array $data): array
    {
        $q = BlogPost::query()->active()->published()->healthPills();
        $ids = array_values(array_filter([(int) ($data['featured_blog_post_id'] ?? 0), ...array_map('intval', $data['secondary_blog_post_ids'] ?? [])]));
        $posts = ($data['selection_mode'] ?? 'automatic') === 'manual' ? $q->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true)) : $q->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get();

        return $posts->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'href' => '/blog/'.$p->slug, 'subtitle' => $p->subtitle, 'category_label' => $p->category_label, 'excerpt' => $p->excerpt])->values()->all();
    }

    private function partners(array $data, Request $request): array
    {
        $q = ConventionPartner::query()->publiclyAvailable()->publicOrder();
        $ids = array_map('intval', $data['partner_ids'] ?? []);
        $featured = ($data['selection_mode'] ?? 'automatic') === 'manual' ? $q->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true)) : $q->limit(2)->get();
        $map = fn ($p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type?->value ?? $p->type, 'logo_url' => PublicMediaUrl::fromPublicDisk($p->logo_path, $request)];

        return ['featured_partners' => $featured->map($map)->values()->all(), 'other_partners' => ConventionPartner::query()->publiclyAvailable()->publicOrder()->whereNotIn('id', $featured->pluck('id'))->get()->map($map)->values()->all()];
    }
}
