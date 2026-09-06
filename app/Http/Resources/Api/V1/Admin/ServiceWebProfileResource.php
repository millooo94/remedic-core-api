<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\ServiceWebProfile;
use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use App\Support\Services\ServiceSectionDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceWebProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ServiceWebProfile|null $profile */
        $profile = $this->webProfile;
        $primary = app(PrimarySpecializationResolver::class)->resolve($this->resource);

        $master = [
            'id' => $this->id,
            'name' => $this->display_name,
            'canonical_name' => $this->canonical_name,
            'master_slug' => $this->slug,
            'price' => $this->importo_prestazione,
            'duration_minutes' => $this->default_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->trashed(),
            'operationally_active' => (bool) $this->is_active,
            'featured_image_path' => $this->featured_image_path,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
            'icon_path' => $primary?->icon_path,
            'icon_url' => PublicMediaUrl::fromPublicDisk($primary?->icon_path, $request),
            'primary_area' => $primary ? [
                'id' => $primary->id,
                'name' => $primary->name,
                'slug' => $primary->slug,
            ] : null,
            'areas' => $this->specializations->map(fn ($area) => [
                'id' => $area->id,
                'name' => $area->name,
                'slug' => $area->slug,
                'is_primary' => (bool) ($area->pivot?->is_primary ?? false),
            ])->values()->all(),
            'professionals' => $this->professionalServices
                ->filter(fn ($link) => $link->professional !== null)
                ->map(fn ($link) => [
                    'id' => $link->professional->id,
                    'display_name' => trim(implode(' ', array_filter([
                        $link->professional->honorific_prefix,
                        $link->professional->full_name,
                    ]))),
                    'is_active' => (bool) $link->professional->is_active,
                ])->values()->all(),
            'professionals_count' => $this->professionalServices->filter(fn ($link) => $link->professional !== null)->count(),
        ];

        return [
            'id' => $this->id,
            'master' => $master,
            'service' => $master,
            'web_profile' => $profile ? $this->profile($profile, $request) : null,
            'is_configured' => $profile !== null,
            'effective_public_visibility' => $this->isEffectivelyVisible(),
            'status' => $profile === null
                ? 'not_configured'
                : ($this->isEffectivelyVisible() ? 'published' : 'not_published'),
        ];
    }

    private function profile(ServiceWebProfile $profile, Request $request): array
    {
        return [
            'id' => $profile->id,
            'service_id' => $profile->service_id,
            'public_slug' => $profile->public_slug,
            'short_description' => $profile->short_description,
            'is_web_enabled' => (bool) $profile->is_web_enabled,
            'is_diagnostic' => (bool) $profile->is_diagnostic,
            'is_aesthetic_medicine' => (bool) $profile->is_aesthetic_medicine,
            'aesthetic_category' => $profile->aesthetic_category,
            'seo_title' => $profile->seo_title,
            'local_seo_title' => $profile->local_seo_title,
            'seo_description' => $profile->seo_description,
            'local_seo_description' => $profile->local_seo_description,
            'seo_h1' => $profile->seo_h1,
            'local_seo_h1' => $profile->local_seo_h1,
            'is_local_seo_enabled' => (bool) $profile->is_local_seo_enabled,
            'canonical_url' => $profile->canonical_url,
            'robots' => $profile->robots?->value ?? $profile->robots,
            'og_title' => $profile->og_title,
            'og_description' => $profile->og_description,
            'og_image_path' => $profile->og_image_path,
            'og_image_url' => PublicMediaUrl::fromPublicDisk($profile->og_image_path ?: $this->featured_image_path, $request),
            'twitter_title' => $profile->twitter_title,
            'twitter_description' => $profile->twitter_description,
            'twitter_image_path' => $profile->twitter_image_path,
            'twitter_image_url' => PublicMediaUrl::fromPublicDisk($profile->twitter_image_path, $request),
            'sections' => $profile->sections
                ->whereIn('key', ServiceSectionDefinition::keys())
                ->sortBy('id')
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'key' => $section->key,
                    'label' => ServiceSectionDefinition::DEFINITIONS[$section->key],
                    'title' => $section->title,
                    'intro' => $section->content,
                    'data' => $section->extra_json ?? new \stdClass,
                    'is_active' => (bool) $section->is_active,
                ])->values()->all(),
            'faqs' => $profile->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'is_active' => (bool) $faq->is_active,
                'is_structured_data' => (bool) $faq->is_structured_data,
            ])->values()->all(),
            'created_at' => optional($profile->created_at)?->toIso8601String(),
            'updated_at' => optional($profile->updated_at)?->toIso8601String(),
        ];
    }
}
