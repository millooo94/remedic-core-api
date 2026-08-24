<?php

namespace App\Services;

use App\Models\FaqItem;
use App\Models\Professional;
use App\Models\Service;
use App\Models\SpecializationWebProfile;
use App\Support\Media\PublicMediaUrl;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MedicalAreaPublicService
{
    public function query(): Builder
    {
        return SpecializationWebProfile::query()
            ->effectivelyVisible()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
                'specialization.services' => fn ($query) => $query
                    ->effectivelyVisible()
                    ->orderBy('service_specialization.sort_order')
                    ->orderBy('services.id'),
                'specialization.services.webProfile',
                'specialization.professionals' => fn ($query) => $query
                    ->where('professionals.is_active', true)
                    ->whereHas('publicProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true))
                    ->orderBy('professional_specialization.sort_order')
                    ->orderBy('professionals.id'),
                'specialization.professionals.publicProfile' => fn ($query) => $query->where('is_web_enabled', true),
                'specialization.professionals.specializations',
            ])
            ->orderBy('list_sort_order')
            ->orderBy(
                fn ($query) => $query->select('name')->from('specializations')
                    ->whereColumn('specializations.id', 'specialization_web_profiles.specialization_id')
            );
    }

    public function listItem(SpecializationWebProfile $profile, Request $request): array
    {
        $master = $profile->specialization;

        return [
            'slug' => $profile->slug,
            'name' => $master->name,
            'short_description' => $profile->short_description ?: '',
            'description' => $profile->short_description ?: '',
            'list_sort_order' => (int) $profile->list_sort_order,
            'services_count' => $master->services->count(),
            'professionals_count' => $master->professionals->count(),
            'icon_url' => PublicMediaUrl::fromPublicDisk($master->icon_path, $request),
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($master->featured_image_path, $request),
        ];
    }

    public function detail(SpecializationWebProfile $profile, Request $request): array
    {
        $master = $profile->specialization;
        $services = $master->services->values();
        $professionals = $master->professionals->values();
        $faqs = $profile->faqs->values();
        $sections = $profile->sections
            ->whereIn('key', MedicalAreaSectionDefinition::keys())
            ->sortBy(fn ($section) => [$section->sort_order, $section->id])
            ->filter(function ($section) use ($services, $professionals, $faqs): bool {
                return match ($section->key) {
                    'services' => $services->isNotEmpty(),
                    'equipe' => $professionals->isNotEmpty(),
                    'faqs' => $faqs->isNotEmpty(),
                    default => true,
                };
            })
            ->map(function ($section) use ($profile, $master, $services, $professionals, $faqs, $request): array {
                $data = match ($section->key) {
                    'hero' => [
                        'name' => $master->name,
                        'short_description' => $profile->short_description,
                        'icon_url' => PublicMediaUrl::fromPublicDisk($master->icon_path, $request),
                        'featured_image_url' => PublicMediaUrl::fromPublicDisk($master->featured_image_path, $request),
                    ],
                    'scope', 'when_useful', 'visit_process' => [
                        'title' => $section->title,
                        'intro' => $section->content,
                        ...($section->extra_json ?? []),
                    ],
                    'services' => [
                        'title' => $section->title,
                        'intro' => $section->content,
                        'items' => $services->map(fn (Service $service) => [
                            'slug' => $service->webProfile->public_slug,
                            'name' => $service->publicLabel(),
                            'short_description' => $service->webProfile->short_description ?: '',
                        ])->values()->all(),
                    ],
                    'faqs' => [
                        'title' => $section->title,
                        'intro' => $section->content,
                        'items' => $faqs->map(fn (FaqItem $faq) => [
                            'question' => $faq->question,
                            'answer' => $faq->answer,
                            'is_structured_data' => (bool) $faq->is_structured_data,
                        ])->values()->all(),
                    ],
                    'equipe' => [
                        'title' => $section->title,
                        'intro' => $section->content,
                        'items' => $professionals->map(fn (Professional $professional) => $this->professional($professional, $request))->values()->all(),
                    ],
                };

                return [
                    'key' => $section->key,
                    'order' => (int) $section->sort_order,
                    'data' => $data,
                ];
            })->values()->all();

        return [
            ...$this->listItem($profile, $request),
            'sections' => $sections,
            'seo' => [
                'title' => $profile->seo_title,
                'local_title' => $profile->local_seo_title,
                'description' => $profile->seo_description,
                'local_description' => $profile->local_seo_description,
                'h1' => $profile->seo_h1,
                'local_h1' => $profile->local_seo_h1,
                'is_local_enabled' => (bool) $profile->is_local_seo_enabled,
                'canonical_url' => $profile->canonical_url,
                'robots' => $profile->robots?->value ?? $profile->robots,
                'og_title' => $profile->og_title,
                'og_description' => $profile->og_description,
                'og_image_url' => PublicMediaUrl::fromPublicDisk($master->featured_image_path, $request),
            ],
        ];
    }

    private function professional(Professional $professional, Request $request): array
    {
        $profile = $professional->publicProfile;
        $primary = $professional->specializations
            ->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX, $area->id])
            ->first();

        return [
            'slug' => $profile->slug,
            'name' => trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
            'short_bio' => $profile->short_bio ?: '',
            'image_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path ?: $profile->profile_image_path, $request),
            'primary_specialization' => $primary ? ['name' => $primary->name] : null,
        ];
    }
}
