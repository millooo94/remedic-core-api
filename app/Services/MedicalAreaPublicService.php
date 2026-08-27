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
        $locale = app(PublicLocaleResolver::class)->resolve(request());
        $query = SpecializationWebProfile::query()
            ->effectivelyVisible()
            ->with([
                'translations',
                'sections' => fn ($query) => $query->active()->ordered()->with('translations'),
                'faqs' => fn ($query) => $query->active()->ordered()->with('translations'),
                'specialization.services' => fn ($query) => $query
                    ->effectivelyVisible()
                    ->orderBy('service_specialization.sort_order')
                    ->orderBy('services.id'),
                'specialization.services.webProfile',
                'specialization.services.webProfile.translations',
                'specialization.professionals' => fn ($query) => $query
                    ->where('professionals.is_active', true)
                    ->whereHas('publicProfile', fn (Builder $profile) => $profile->where('is_web_enabled', true))
                    ->orderBy('professional_specialization.sort_order')
                    ->orderBy('professionals.id'),
                'specialization.professionals.publicProfile' => fn ($query) => $query->where('is_web_enabled', true),
                'specialization.professionals.publicProfile.translations',
                'specialization.professionals.specializations',
            ])
            ->orderBy('list_sort_order')
            ->orderBy(
                fn ($query) => $query->select('name')->from('specializations')
                    ->whereColumn('specializations.id', 'specialization_web_profiles.specialization_id')
            );

        return app(LocalizedContentResolver::class)->publicTranslations($query, $locale);
    }

    public function listItem(SpecializationWebProfile $profile, Request $request): array
    {
        $locale = app(PublicLocaleResolver::class)->resolve($request);
        $profile = app(LocalizedContentResolver::class)->project($profile, $locale) ?? abort(404);
        $master = $profile->specialization;

        return [
            'slug' => $profile->slug,
            'href' => app(LocalizedRouteRegistry::class)->path('medical_areas', $locale, $profile->slug),
            'name' => $profile->localizedTranslation?->title ?: $master->name,
            'short_description' => $profile->short_description ?: '',
            'description' => $profile->short_description ?: '',
            'services_count' => $master->services->count(),
            'professionals_count' => $master->professionals->count(),
            'icon_url' => PublicMediaUrl::fromPublicDisk($master->icon_path, $request),
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($master->featured_image_path, $request),
        ];
    }

    public function detail(SpecializationWebProfile $profile, Request $request): array
    {
        $locale = app(PublicLocaleResolver::class)->resolve($request);
        $profile = app(LocalizedContentResolver::class)->project($profile, $locale) ?? abort(404);
        abort_unless(app(LocalizedContentResolver::class)->hasCompleteStructure($profile, $locale), 404);
        $master = $profile->specialization;
        $services = $master->services->filter(fn (Service $service): bool => $this->service($service, $request) !== null)->values();
        $professionals = $master->professionals->filter(fn (Professional $professional): bool => $this->professional($professional, $request) !== null)->values();
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
                        'name' => $profile->localizedTranslation?->title ?: $master->name,
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
                        'items' => $services->map(fn (Service $service) => $this->service($service, $request))->filter()->values()->all(),
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
                        'items' => $professionals->map(fn (Professional $professional) => $this->professional($professional, $request))->filter()->values()->all(),
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
            'seo' => [...app(PublicSeoResolver::class)->resolve([
                'title' => $master->name,
                'description' => $profile->short_description,
                'seo_title' => $profile->seo_title,
                'seo_description' => $profile->seo_description,
                'robots' => $profile->robots,
                'og_title' => $profile->og_title,
                'og_description' => $profile->og_description,
                'image_url' => PublicMediaUrl::fromPublicDisk($master->featured_image_path, $request),
            ], app(LocalizedRouteRegistry::class)->path('medical_areas', $locale, $profile->slug), $request),
                'local_title' => $profile->local_seo_title,
                'local_description' => $profile->local_seo_description,
                'h1' => $profile->seo_h1,
                'local_h1' => $profile->local_seo_h1,
                'is_local_enabled' => (bool) $profile->is_local_seo_enabled,
            ],
        ];
    }

    private function professional(Professional $professional, Request $request): ?array
    {
        $locale = app(PublicLocaleResolver::class)->resolve($request);
        $profile = app(LocalizedContentResolver::class)->project($professional->publicProfile, $locale);
        if ($profile === null) {
            return null;
        }
        $primary = $professional->specializations
            ->sortBy(fn ($area) => [($area->pivot?->is_primary ?? false) ? 0 : 1, $area->pivot?->sort_order ?? PHP_INT_MAX, $area->id])
            ->first();

        return [
            'slug' => $profile->slug,
            'name' => $profile->localizedTranslation?->title ?: trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
            'short_bio' => $profile->short_bio ?: '',
            'image_url' => PublicMediaUrl::fromPublicDisk($professional->avatar_path ?: $profile->profile_image_path, $request),
            'primary_specialization' => $primary ? ['name' => $primary->name] : null,
        ];
    }

    private function service(Service $service, Request $request): ?array
    {
        $locale = app(PublicLocaleResolver::class)->resolve($request);
        $profile = app(LocalizedContentResolver::class)->project($service->webProfile, $locale);
        if ($profile === null) {
            return null;
        }

        return [
            'slug' => $profile->public_slug,
            'name' => $profile->localizedTranslation?->title ?: $service->publicLabel(),
            'short_description' => $profile->short_description ?: '',
        ];
    }
}
