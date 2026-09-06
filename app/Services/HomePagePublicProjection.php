<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\ConventionPartner;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteSetting;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\HomePageRegistry;
use Illuminate\Http\Request;

class HomePagePublicProjection
{
    public function __construct(private readonly MedicalAreaPublicService $areas, private readonly ServicePublicContentService $services, private readonly CheckupPublicContentService $checkups, private readonly ContactCenterDataResolver $center, private readonly ContactPageMediaResolver $contactMedia, private readonly PublicLocaleResolver $locales, private readonly LocalizedContentResolver $localized, private readonly LocalizedRouteRegistry $routes, private readonly SiteNavigationProjectionService $navigation) {}

    /** @return array<string,mixed> */
    public function project(Page $page, Request $request): array
    {
        $sections = $page->sections()->active()->orderBy('id')->get()->values()->map(function ($section, int $order) use ($request): array {
            $data = $section->extra_json ?? [];
            if (in_array($section->key, ['conventions', 'faq', 'contact', 'newsletter'], true)) {
                $data += HomePageRegistry::defaults($section->key);
            }
            $data = match ($section->key) {
                'medical_areas' => [...$data, 'items' => $this->areas->query()->limit($this->limit($data))->get()->map(fn ($item) => $this->areas->listItem($item, $request))->values()->all()],
                'professionals' => [...$data, 'items' => $this->professionals($request, $this->limit($data))],
                'diagnostics' => [...$data, 'items' => $this->services->query()->whereHas('webProfile', fn ($q) => $q->where('is_diagnostic', true))->limit($this->limit($data))->get()->map(fn ($item) => $this->services->listItem($item, $request))->values()->all()],
                'checkups' => [...$data, 'items' => $this->checkups->query()->limit(3)->get()->map(fn ($item) => $this->checkups->listItem($item, $request))->values()->all()],
                'aesthetic_medicine' => [...$data, 'items' => $this->services->query()->whereHas('webProfile', fn ($q) => $q->where('is_aesthetic_medicine', true))->limit($this->limit($data))->get()->map(fn ($item) => $this->services->listItem($item, $request))->values()->all()],
                'health_pills' => [...$data, 'items' => $this->posts($data)],
                'conventions' => [...$data, ...$this->partners($data, $request)],
                'faq' => [...$data, 'items' => $section->sectionable->faqs()->where('is_active', true)->orderBy('id')->get()->map(fn ($faq) => ['question' => $faq->question, 'answer' => $faq->answer, 'is_structured_data' => (bool) $faq->is_structured_data])->all()],
                'contact' => [...$data, 'center' => $this->center->resolve(SiteSetting::current()), 'shared_media' => $this->contactMedia->resolve($request)],
                default => $data,
            };
            $data['media'] = $this->media($data, $request);
            if ($section->key === 'hero') {
                $defaults = HomePageRegistry::defaults('hero');
                $data['primary_cta'] = $this->cta($data['primary_cta_label'] ?? null, $data['primary_cta_target'] ?? $defaults['primary_cta_target'], $request, ['external_url' => $data['primary_cta_external_url'] ?? null, 'whatsapp_message' => $data['primary_cta_whatsapp_message'] ?? null]);
                $data['secondary_cta'] = $this->cta($data['secondary_cta_label'] ?? null, $data['secondary_cta_target'] ?? $defaults['secondary_cta_target'], $request, ['external_url' => $data['secondary_cta_external_url'] ?? null, 'whatsapp_message' => $data['secondary_cta_whatsapp_message'] ?? null]);
                $data['booking_action'] = ['type' => 'booking'];
                $data['search_action'] = $this->indexAction('medical_areas_index', $request);
            }
            if (in_array($section->key, ['center_intro', 'conventions'], true)) {
                $defaults = HomePageRegistry::defaults($section->key);
                $data['cta'] = $this->cta($data['cta_label'] ?? null, $data['cta_target'] ?? $defaults['cta_target'], $request, ['external_url' => $data['cta_external_url'] ?? null, 'whatsapp_message' => $data['cta_whatsapp_message'] ?? null]);
            }
            if ($section->key === 'faq') {
                $defaults = HomePageRegistry::defaults('faq');
                $data['cta'] = $this->cta($data['cta_label'] ?? null, $data['cta_target'] ?? $defaults['cta_target'], $request, ['external_url' => $data['cta_external_url'] ?? null, 'whatsapp_message' => $data['cta_whatsapp_message'] ?? null]);
            }
            if ($section->key === 'contact') {
                $defaults = HomePageRegistry::defaults('contact');
                $data['primary_cta'] = $this->cta($data['primary_cta_label'] ?? null, $data['primary_cta_target'] ?? $defaults['primary_cta_target'], $request, ['external_url' => $data['primary_cta_external_url'] ?? null, 'whatsapp_message' => $data['primary_cta_whatsapp_message'] ?? null]);
                $data['secondary_cta'] = $this->cta($data['secondary_cta_label'] ?? null, $data['secondary_cta_target'] ?? $defaults['secondary_cta_target'], $request, ['external_url' => $data['secondary_cta_external_url'] ?? null, 'whatsapp_message' => $data['secondary_cta_whatsapp_message'] ?? null]);
            }
            if ($target = match ($section->key) {
                'medical_areas' => 'medical_areas_index',
                'professionals' => 'equipe_index',
                'checkups' => 'checkups_index',
                'diagnostics' => 'diagnostics_index',
                'aesthetic_medicine' => 'aesthetic_medicine_index',
                'news' => 'news_index',
                'health_pills' => 'health_pills_index',
                default => null,
            }) {
                $data['index_action'] = $this->indexAction($target, $request);
            }
            if ($section->key === 'newsletter') {
                $data['component_type'] = 'newsletter_signup';
                $data['submit_action'] = $this->cta($data['submit_label'] ?? null, $data['submit_target'] ?? 'newsletter_subscription', $request, ['external_url' => $data['submit_external_url'] ?? null, 'whatsapp_message' => $data['submit_whatsapp_message'] ?? null]);
                $data['privacy_action'] = $this->cta('Privacy Policy', 'privacy', $request);
            }

            return ['key' => $section->key, 'order' => $order, 'data' => $data];
        })->values()->all();

        return ['slug' => $page->slug, 'canonical_url' => '/', 'sections' => $sections];
    }

    private function limit(array $data): int
    {
        return min(24, max(1, (int) ($data['max_items'] ?? 6)));
    }

    private function indexAction(string $key, Request $request): array
    {
        $action = ['target' => $key];
        $page = SiteIndexPage::query()->where('internal_key', $key)->first();
        $locale = $this->locales->resolve($request);
        $translation = $page?->translations()->where('locale', $locale->value)->first();
        if ($page?->isPubliclyAvailable() && ($locale->value === 'it' || $translation?->isPubliclyAvailable())) {
            $action['href'] = $this->routes->path(match ($key) {
                'medical_areas_index' => 'medical_areas',
                'equipe_index' => 'team',
                'checkups_index' => 'checkups',
                'diagnostics_index' => 'diagnostics',
                'aesthetic_medicine_index' => 'aesthetic_medicine',
                'news_index' => 'news',
                'health_pills_index' => 'health_tips',
            }, $locale);
        }

        return $action;
    }

    private function media(array $data, Request $request): array
    {
        return collect($data['media'] ?? [])->mapWithKeys(fn ($media, $slot) => [
            $slot => [
                'url' => PublicMediaUrl::fromPublicDisk($media['path'] ?? null, $request),
                'alt' => $media['alt'] ?? null,
            ],
        ])->all();
    }

    /** @return array<string,mixed>|null */
    private function cta(mixed $label, mixed $target, Request $request, array $context = []): ?array
    {
        if (! is_string($label) || $label === '' || ! is_string($target) || $target === '') {
            return null;
        }
        $resolved = $this->navigation->target($target, $this->locales->resolve($request), $context);

        return $resolved['is_action']
            ? array_filter(['label' => $label, 'action' => $resolved['action'] ?? $target, 'href' => $resolved['href']], static fn (mixed $value): bool => $value !== null)
            : ($resolved['href'] ? ['label' => $label, 'href' => $resolved['href']] : null);
    }

    private function professionals(Request $request, int $limit): array
    {
        $locale = $this->locales->resolve($request);

        return $this->localized->publicTranslations(ProfessionalPublicProfile::query()->effectivelyVisible()->with(['translations', 'professional.specializations']), $locale)->orderBy('id')->limit($limit)->get()->map(function ($profile) use ($request, $locale) {
            $profile = $this->localized->project($profile, $locale) ?? abort(404);
            $p = $profile->professional;
            $area = $p->specializations->sortBy(fn ($s) => [($s->pivot->is_primary ?? false) ? 0 : 1, $s->pivot->sort_order ?? 999])->first();

            return ['slug' => $profile->slug, 'href' => $this->routes->path('team', $locale, $profile->slug), 'name' => trim(($p->honorific_prefix ? $p->honorific_prefix.' ' : '').$p->full_name), 'short_bio' => $profile->short_bio ?: '', 'image_url' => PublicMediaUrl::fromPublicDisk($p->avatar_path, $request), 'primary_specialization' => $area?->name];
        })->all();
    }

    private function posts(array $data): array
    {
        $locale = $this->locales->resolve(request());
        $q = $this->localized->publicTranslations(BlogPost::query()->with('translations')->active()->published()->healthPills(), $locale);
        $ids = array_values(array_filter([(int) ($data['featured_blog_post_id'] ?? 0), ...array_map('intval', $data['secondary_blog_post_ids'] ?? [])]));
        $posts = ($data['selection_mode'] ?? 'automatic') === 'manual' ? $q->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true)) : $q->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get();

        return $posts->map(function ($post) use ($locale) {
            $post = $this->localized->project($post, $locale) ?? abort(404);

            return ['title' => $post->title, 'slug' => $post->slug, 'href' => $this->routes->path('health_tips', $locale, $post->slug), 'subtitle' => $post->subtitle, 'category_label' => $post->category_label, 'excerpt' => $post->excerpt];
        })->values()->all();
    }

    private function partners(array $data, Request $request): array
    {
        $q = ConventionPartner::query()->publiclyAvailable()->publicOrder();
        $ids = array_map('intval', $data['partner_ids'] ?? []);
        $featured = $ids !== []
            ? $q->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids, true))
            : $q->limit(2)->get();
        $map = fn ($p) => ['name' => $p->name, 'type' => $p->type?->value ?? $p->type, 'logo_url' => PublicMediaUrl::fromPublicDisk($p->logo_path, $request)];

        return ['featured_partners' => $featured->map($map)->values()->all(), 'other_partners' => ConventionPartner::query()->publiclyAvailable()->publicOrder()->whereNotIn('id', $featured->pluck('id'))->get()->map($map)->values()->all()];
    }
}
