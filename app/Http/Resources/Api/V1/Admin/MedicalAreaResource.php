<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\SpecializationWebProfile;
use App\Support\Media\PublicMediaUrl;
use App\Support\MedicalAreas\MedicalAreaSectionDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicalAreaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SpecializationWebProfile|null $profile */
        $profile = $this->webProfile;

        return [
            'id' => $this->id,
            'master' => [
                'id' => $this->id,
                'name' => $this->name,
                'professional_title_male' => $this->professional_title_male,
                'professional_title_female' => $this->professional_title_female,
                'slug' => $this->slug,
                'color_hex' => $this->color_hex,
                'icon_path' => $this->icon_path,
                'icon_url' => PublicMediaUrl::fromPublicDisk($this->icon_path, $request),
                'featured_image_path' => $this->featured_image_path,
                'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
                'is_active' => (bool) $this->is_active,
                'sort_order' => (int) $this->sort_order,
                'services_count' => (int) ($this->services_count ?? $this->services->count()),
                'professionals_count' => (int) ($this->professionals_count ?? $this->professionals->count()),
            ],
            'web_profile' => $profile ? $this->profile($profile) : null,
            'is_configured' => $profile !== null,
            'effective_public_visibility' => (bool) $this->is_active && (bool) $profile?->is_web_enabled,
            'status' => $profile === null
                ? 'not_configured'
                : (((bool) $this->is_active && (bool) $profile->is_web_enabled) ? 'published' : 'not_published'),
            'derived' => [
                'services' => $this->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->publicLabel(),
                    'is_active' => (bool) $service->is_active,
                    'is_web_active' => (bool) $service->is_web_active,
                ])->values()->all(),
                'equipe' => $this->professionals
                    ->filter(fn ($professional) => $professional->publicProfile !== null)
                    ->map(fn ($professional) => [
                        'id' => $professional->id,
                        'display_name' => trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
                        'is_active' => (bool) $professional->is_active,
                        'is_web_enabled' => (bool) $professional->publicProfile?->is_web_enabled,
                    ])->values()->all(),
            ],
        ];
    }

    private function profile(SpecializationWebProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'specialization_id' => $profile->specialization_id,
            'slug' => $profile->slug,
            'short_description' => $profile->short_description,
            'is_web_enabled' => (bool) $profile->is_web_enabled,
            'list_sort_order' => (int) $profile->list_sort_order,
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
            'og_image_path' => $this->featured_image_path,
            'sections' => $profile->sections
                ->whereIn('key', MedicalAreaSectionDefinition::keys())
                ->sortBy(fn ($section) => [$section->sort_order, $section->id])
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'key' => $section->key,
                    'label' => MedicalAreaSectionDefinition::DEFINITIONS[$section->key],
                    'title' => $section->title,
                    'intro' => $section->content,
                    'data' => $section->extra_json ?? new \stdClass,
                    'sort_order' => (int) $section->sort_order,
                    'is_active' => (bool) $section->is_active,
                ])->values()->all(),
            'faqs' => $profile->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'sort_order' => (int) $faq->sort_order,
                'is_active' => (bool) $faq->is_active,
                'is_structured_data' => (bool) $faq->is_structured_data,
            ])->values()->all(),
            'created_at' => optional($profile->created_at)?->toIso8601String(),
            'updated_at' => optional($profile->updated_at)?->toIso8601String(),
        ];
    }
}
