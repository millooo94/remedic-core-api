<?php

namespace App\Services;

use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Support\Checkups\CheckupSectionDefinition;
use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CheckupPublicContentService
{
    public function query(): Builder
    {
        return Checkup::query()->effectivelyVisible()->with([
            'webProfile.sections' => fn ($query) => $query->active()->ordered(),
            'webProfile.faqs' => fn ($query) => $query->active()->ordered(),
            'items.service.webProfile', 'items.service.specializations.webProfile',
            'items.service.professionalServices' => fn ($query) => $query
                ->where('is_active', true)->where('is_visible_public', true)
                ->whereHas('professional', fn (Builder $professional) => $professional
                    ->where('is_active', true)
                    ->whereHas('publicProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true)))
                ->orderBy('public_sort_order')->orderBy('id'),
            'items.service.professionalServices.professional.publicProfile',
            'items.service.professionalServices.professional.specializations',
        ])->orderBy(CheckupWebProfile::query()->select('list_sort_order')
            ->whereColumn('checkup_web_profiles.checkup_id', 'checkups.id')->limit(1))
            ->orderBy('display_name')->orderBy('id');
    }

    public function listItem(Checkup $checkup, Request $request): array
    {
        $profile = $checkup->webProfile;

        return [
            'slug' => $profile->public_slug,
            'name' => $checkup->display_name,
            'category_label' => $profile->category_label,
            'short_description' => $profile->short_description ?: '',
            'price' => $checkup->price_amount,
            'duration_minutes' => $checkup->indicative_duration_minutes,
            'is_operationally_available' => $checkup->isOperationallyAvailable(),
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($checkup->featured_image_path, $request),
            'icon_url' => PublicMediaUrl::fromPublicDisk($checkup->icon_path, $request),
            'list_sort_order' => (int) $profile->list_sort_order,
        ];
    }

    public function indexItem(Checkup $checkup, Request $request): array
    {
        $profile = $checkup->webProfile;
        $slug = $profile->public_slug;

        return [
            ...$this->listItem($checkup, $request),
            'id' => $checkup->id,
            'public_slug' => $slug,
            'href' => '/check-up/'.$slug,
            'anchor' => 'checkup-'.$slug,
            'display_name' => $checkup->display_name,
            'image_url' => PublicMediaUrl::fromPublicDisk($checkup->featured_image_path, $request),
            'duration' => $checkup->indicative_duration_minutes,
            'duration_label' => $checkup->indicative_duration_minutes ? $checkup->indicative_duration_minutes.' min' : null,
            'included_services' => $this->includedServices($checkup, $request)->all(),
        ];
    }

    public function detail(Checkup $checkup, Request $request): array
    {
        $profile = $checkup->webProfile;
        $included = $this->includedServices($checkup, $request);
        $areas = $checkup->items->flatMap(fn ($item) => $item->service?->specializations ?? [])
            ->unique('id')->map(fn ($area) => $this->area($area, $request))->values();
        $professionals = $checkup->items->flatMap(fn ($item) => $item->service?->professionalServices ?? [])
            ->map(fn ($link) => $link->professional)->filter()->unique('id')
            ->map(fn ($professional) => $this->professional($professional, $request))->values();
        $faqs = $profile->faqs->map(fn ($faq) => [
            'question' => $faq->question, 'answer' => $faq->answer,
            'is_structured_data' => (bool) $faq->is_structured_data,
        ])->values();
        $related = $this->query()->whereKeyNot($checkup->id)->limit(3)->get()
            ->map(fn (Checkup $other) => $this->listItem($other, $request))->values();

        $sections = $profile->sections->whereIn('key', CheckupSectionDefinition::keys())
            ->sortBy(fn ($section) => [$section->sort_order, $section->id])
            ->map(fn ($section) => $this->section($section, $checkup, $profile, $included, $professionals, $faqs, $related, $request))
            ->filter()->values()->all();

        $seo = [...app(PublicSeoResolver::class)->resolve([
            'title' => $checkup->display_name,
            'description' => $profile->short_description,
            'seo_title' => $profile->seo_title,
            'seo_description' => $profile->seo_description,
            'robots' => $profile->robots,
            'og_title' => $profile->og_title,
            'og_description' => $profile->og_description,
            'image_url' => PublicMediaUrl::fromPublicDisk($checkup->featured_image_path, $request),
        ], '/check-up/'.$profile->public_slug, $request),
            'local_title' => $profile->local_seo_title,
            'local_description' => $profile->local_seo_description,
            'h1' => $profile->seo_h1, 'local_h1' => $profile->local_seo_h1,
            'is_local_enabled' => (bool) $profile->is_local_seo_enabled,
        ];

        return [
            'checkup' => [
                'id' => $checkup->id, 'name' => $checkup->display_name,
                'price' => $checkup->price_amount, 'duration_minutes' => $checkup->indicative_duration_minutes,
                'operationally_active' => (bool) $checkup->is_active,
                'operationally_available' => $checkup->isOperationallyAvailable(),
                'featured_image_url' => PublicMediaUrl::fromPublicDisk($checkup->featured_image_path, $request),
                'icon_url' => PublicMediaUrl::fromPublicDisk($checkup->icon_path, $request),
            ],
            'web_profile' => [
                'public_slug' => $profile->public_slug, 'category_label' => $profile->category_label,
                'short_description' => $profile->short_description ?: '', 'seo' => $seo,
            ],
            ...$this->listItem($checkup, $request),
            'areas' => $areas->all(),
            'professionals' => $professionals->all(),
            'included_services' => $included->all(),
            'sections' => $sections,
            'seo' => $seo,
        ];
    }

    private function section($section, Checkup $checkup, CheckupWebProfile $profile, $included, $professionals, $faqs, $related, Request $request): ?array
    {
        $data = match ($section->key) {
            'hero' => [
                'eyebrow' => $profile->category_label ?: 'CHECK-UP', 'name' => $checkup->display_name,
                'short_description' => $profile->short_description, 'price' => $checkup->price_amount,
                'duration_minutes' => $checkup->indicative_duration_minutes,
                'is_operationally_available' => $checkup->isOperationallyAvailable(),
                'featured_image_url' => PublicMediaUrl::fromPublicDisk($checkup->featured_image_path, $request),
                'icon_url' => PublicMediaUrl::fromPublicDisk($checkup->icon_path, $request),
            ],
            'included_services' => $included->isEmpty() ? null : ['title' => $section->title, 'intro' => $section->content, 'items' => $included->all()],
            'price' => ['title' => $section->title, 'intro' => $section->content, 'amount' => $checkup->price_amount],
            'faqs' => $faqs->isEmpty() ? null : ['title' => $section->title, 'intro' => $section->content, 'items' => $faqs->all()],
            'equipe' => $professionals->isEmpty() ? null : ['title' => $section->title, 'intro' => $section->content, 'items' => $professionals->all()],
            'related_checkups' => $related->isEmpty() ? null : ['title' => $section->title, 'intro' => $section->content, 'items' => $related->all()],
            default => ['title' => $section->title, 'intro' => $section->content, ...($section->extra_json ?? [])],
        };

        return $data === null ? null : ['key' => $section->key, 'order' => (int) $section->sort_order, 'data' => $data];
    }

    private function includedService($item, Request $request): array
    {
        $service = $item->service;
        $visible = ! $service->trashed() && $service->isEffectivelyVisible();
        $primary = app(PrimarySpecializationResolver::class)->resolve($service);

        return [
            'id' => $service->id, 'name' => $service->publicLabel(), 'order' => (int) $item->sort_order,
            'price' => $service->importo_prestazione,
            'duration_minutes' => $service->default_duration_minutes,
            'is_active' => (bool) $service->is_active, 'is_archived' => $service->trashed(),
            'is_publicly_visible' => $visible,
            'href' => $visible && $service->webProfile?->public_slug ? '/prestazioni/'.$service->webProfile->public_slug : null,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($service->featured_image_path, $request),
            'icon_url' => PublicMediaUrl::fromPublicDisk($primary?->icon_path, $request),
        ];
    }

    private function includedServices(Checkup $checkup, Request $request)
    {
        return $checkup->items->sortBy(fn ($item) => [$item->sort_order, $item->id])
            ->filter(fn ($item) => $item->service !== null)
            ->map(fn ($item) => $this->includedService($item, $request))->values();
    }

    private function area($area, Request $request): array
    {
        $profile = $area->webProfile;
        $visible = (bool) $area->is_active && (bool) $profile?->is_web_enabled;

        return [
            'name' => $area->name,
            'slug' => $visible ? $profile->slug : null,
            'href' => $visible ? '/aree-mediche/'.$profile->slug : null,
            'icon_url' => PublicMediaUrl::fromPublicDisk($area->icon_path, $request),
        ];
    }

    private function professional($professional, Request $request): array
    {
        $profile = $professional->publicProfile;
        $primary = $professional->specializations->sortBy(fn ($area) => [
            ($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX, $area->id,
        ])->first();

        return [
            'slug' => $profile->slug,
            'href' => '/equipe/'.$profile->slug,
            'name' => trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
            'short_bio' => $profile->short_bio ?: '', 'specialization' => $primary?->name,
            'image_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path ?: $profile->profile_image_path, $request),
        ];
    }
}
