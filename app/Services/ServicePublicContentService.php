<?php

namespace App\Services;

use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use App\Support\Services\ServiceSectionDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ServicePublicContentService
{
    public function query(): Builder
    {
        return Service::query()
            ->effectivelyVisible()
            ->with([
                'webProfile.sections' => fn ($query) => $query->active()->ordered(),
                'webProfile.faqs' => fn ($query) => $query->active()->ordered(),
                'specializations.webProfile',
                'professionalServices' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible_public', true)
                    ->whereHas('professional', fn (Builder $professional) => $professional
                        ->where('is_active', true)
                        ->whereHas('publicProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true)))
                    ->orderBy('public_sort_order')
                    ->orderBy('id'),
                'professionalServices.professional.publicProfile',
                'professionalServices.professional.specializations',
            ])
            ->orderBy(
                ServiceWebProfile::query()
                    ->select('list_sort_order')
                    ->whereColumn('service_web_profiles.service_id', 'services.id')
                    ->limit(1)
            )
            ->orderBy('display_name')
            ->orderBy('id');
    }

    public function listItem(Service $service, Request $request): array
    {
        $profile = $service->webProfile;
        $primary = app(PrimarySpecializationResolver::class)->resolve($service);

        return [
            'slug' => $profile->public_slug,
            'name' => $service->publicLabel(),
            'short_description' => $profile->short_description ?: '',
            'price' => $service->importo_prestazione,
            'duration_minutes' => $service->default_duration_minutes,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request),
            'icon_url' => PublicMediaUrl::fromPublicDisk($primary?->icon_path, $request),
            'primary_area' => $this->publicArea($primary, $request),
            'list_sort_order' => (int) $profile->list_sort_order,
        ];
    }

    public function detail(Service $service, Request $request): array
    {
        $profile = $service->webProfile;
        $primary = app(PrimarySpecializationResolver::class)->resolve($service);
        $professionals = $service->professionalServices
            ->filter(fn ($link) => $link->professional?->publicProfile !== null)
            ->map(fn ($link) => $this->professional($link->professional, $request))
            ->values();
        $areas = $service->specializations
            ->map(fn ($area) => $this->publicArea($area, $request))
            ->filter()
            ->values();
        $faqs = $profile->faqs->map(fn ($faq) => [
            'question' => $faq->question,
            'answer' => $faq->answer,
            'is_structured_data' => (bool) $faq->is_structured_data,
        ])->values();

        $sections = $profile->sections
            ->whereIn('key', ServiceSectionDefinition::keys())
            ->sortBy(fn ($section) => [$section->sort_order, $section->id])
            ->map(fn ($section) => $this->section($section, $service, $profile, $primary, $areas, $professionals, $faqs, $request))
            ->filter()
            ->values()
            ->all();

        return [
            'slug' => $profile->public_slug,
            'name' => $service->publicLabel(),
            'short_description' => $profile->short_description ?: '',
            'price' => $service->importo_prestazione,
            'duration_minutes' => $service->default_duration_minutes,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request),
            'icon_url' => PublicMediaUrl::fromPublicDisk($primary?->icon_path, $request),
            'primary_area' => $this->publicArea($primary, $request),
            'areas' => $areas->all(),
            'professionals' => $professionals->all(),
            'sections' => $sections,
            'seo' => [...app(PublicSeoResolver::class)->resolve([
                'title' => $service->publicLabel(),
                'description' => $profile->short_description,
                'seo_title' => $profile->seo_title,
                'seo_description' => $profile->seo_description,
                'robots' => $profile->robots,
                'og_title' => $profile->og_title,
                'og_description' => $profile->og_description,
                'image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request),
            ], '/prestazioni/'.$profile->public_slug, $request),
                'local_title' => $profile->local_seo_title,
                'local_description' => $profile->local_seo_description,
                'h1' => $profile->seo_h1,
                'local_h1' => $profile->local_seo_h1,
                'is_local_enabled' => (bool) $profile->is_local_seo_enabled,
            ],
        ];
    }

    public function legacyListItem(Service $service, Request $request): array
    {
        $item = $this->listItem($service, $request);

        return [
            ...$item,
            'specialization' => $item['primary_area']['name'] ?? 'Prestazioni',
            'category' => 'visite',
            'description' => $item['short_description'],
            'featured' => false,
        ];
    }

    public function legacyDetail(Service $service, Request $request): array
    {
        $canonical = $this->detail($service, $request);
        $sections = collect($canonical['sections']);

        return [
            'slug' => $canonical['slug'],
            'name' => $canonical['name'],
            'category' => 'visite',
            'specialization' => $canonical['primary_area']['name'] ?? 'Prestazioni',
            'featured_image_url' => $canonical['featured_image_url'],
            'icon_url' => $canonical['icon_url'],
            'short_description' => $canonical['short_description'],
            'description' => $sections->firstWhere('key', 'what_is')['data']['intro'] ?? $canonical['short_description'],
            'price' => $canonical['price'],
            'duration' => $canonical['duration_minutes'] !== null ? $canonical['duration_minutes'].' min' : null,
            'preparation' => $sections->firstWhere('key', 'preparation')['data']['intro'] ?? null,
            'modalita' => 'In sede',
            'sections' => $canonical['sections'],
            'doctors' => $canonical['professionals'],
            'related_prestazioni' => [],
            'faq' => $sections->firstWhere('key', 'faqs')['data']['items'] ?? [],
            'featured' => false,
            'seo' => $canonical['seo'],
        ];
    }

    private function section($section, Service $service, ServiceWebProfile $profile, $primary, $areas, $professionals, $faqs, Request $request): ?array
    {
        $data = match ($section->key) {
            'hero' => [
                'eyebrow' => 'PRESTAZIONE',
                'name' => $service->publicLabel(),
                'short_description' => $profile->short_description,
                'price' => $service->importo_prestazione,
                'duration_minutes' => $service->default_duration_minutes,
                'featured_image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request),
                'icon_url' => PublicMediaUrl::fromPublicDisk($primary?->icon_path, $request),
                'primary_area' => $this->publicArea($primary, $request),
                'areas' => $areas->all(),
            ],
            'price' => $service->importo_prestazione === null ? null : [
                'title' => $section->title,
                'intro' => $section->content,
                'amount' => $service->importo_prestazione,
            ],
            'faqs' => $faqs->isEmpty() ? null : [
                'title' => $section->title,
                'intro' => $section->content,
                'items' => $faqs->all(),
            ],
            'equipe' => $professionals->isEmpty() ? null : [
                'title' => $section->title,
                'intro' => $section->content,
                'items' => $professionals->all(),
            ],
            default => [
                'title' => $section->title,
                'intro' => $section->content,
                ...($section->extra_json ?? []),
            ],
        };

        return $data === null ? null : [
            'key' => $section->key,
            'order' => (int) $section->sort_order,
            'data' => $data,
        ];
    }

    private function publicArea($area, Request $request): ?array
    {
        $profile = $area?->webProfile;
        if ($area === null || ! $area->is_active || $profile === null || ! $profile->is_web_enabled) {
            return null;
        }

        return [
            'name' => $area->name,
            'slug' => $profile->slug,
            'href' => '/aree-mediche/'.$profile->slug,
            'icon_url' => PublicMediaUrl::fromPublicDisk($area->icon_path, $request),
        ];
    }

    private function professional($professional, Request $request): array
    {
        $profile = $professional->publicProfile;
        $primary = $professional->specializations
            ->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX, $area->id])
            ->first();

        return [
            'slug' => $profile->slug,
            'name' => trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
            'short_bio' => $profile->short_bio ?: '',
            'specialization' => $primary?->name,
            'image_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path ?: $profile->profile_image_path, $request),
        ];
    }
}
