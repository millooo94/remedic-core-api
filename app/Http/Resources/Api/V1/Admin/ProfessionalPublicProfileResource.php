<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\Media\PublicMediaUrl;
use App\Support\Professionals\EquipeSectionDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalPublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $professional = $this->professional;
        $specializations = $professional->specializations
            ->sortBy(fn ($item) => [
                ($item->pivot?->is_primary ?? false) ? 0 : 1,
                $item->pivot?->sort_order ?? PHP_INT_MAX,
                $item->id,
            ])->values();
        $primary = $specializations->first();
        $avatarPath = $professional->avatar_path ?: $this->profile_image_path;

        $professionalProjection = [
            'id' => $professional->id,
            'display_name' => trim(implode(' ', array_filter([$professional->honorific_prefix, $professional->full_name]))),
            'full_name' => $professional->full_name,
            'honorific_prefix' => $professional->honorific_prefix,
            'subject_type' => $professional->subject_type?->value ?? $professional->subject_type,
            'is_active' => (bool) $professional->is_active,
            'is_archived' => false,
            'operationally_active' => (bool) $professional->is_active,
            'avatar_path' => $professional->avatar_path,
            'avatar_url' => PublicMediaUrl::fromPublicDisk($avatarPath, $request),
            'primary_specialization' => $primary ? $this->specialization($primary) : null,
            'specializations' => $specializations->map(fn ($item) => $this->specialization($item))->all(),
            'services' => $professional->professionalServices
                ->filter(fn ($link) => $link->is_active
                    && $link->is_visible_public
                    && $link->service !== null
                    && $link->service->isEffectivelyVisible())
                ->map(fn ($link) => [
                    'id' => $link->service->id,
                    'name' => $link->service->publicLabel(),
                    'is_visible_public' => (bool) $link->is_visible_public,
                ])->values()->all(),
            'credentials' => [
                'degrees' => $professional->degrees->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'awarded_on' => optional($item->awarded_on)?->toDateString(),
                    'sort_order' => (int) $item->sort_order,
                ])->values()->all(),
                'academic_specializations' => $professional->academicSpecializations->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'awarded_on' => optional($item->awarded_on)?->toDateString(),
                    'sort_order' => (int) $item->sort_order,
                ])->values()->all(),
                'board_registrations' => $professional->boardRegistrations->map(fn ($item) => [
                    'id' => $item->id,
                    'board_name' => $item->board_name,
                    'registration_number' => $item->registration_number,
                    'registered_on' => optional($item->registered_on)?->toDateString(),
                    'sort_order' => (int) $item->sort_order,
                ])->values()->all(),
            ],
            'career_experiences' => $professional->careerExperiences->map(fn ($item) => [
                'id' => $item->id,
                'year_from' => (int) $item->year_from,
                'year_to' => $item->year_to !== null ? (int) $item->year_to : null,
                'is_current' => (bool) $item->is_current,
                'title' => $item->title,
                'organization' => $item->organization,
                'description' => $item->description,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
        ];

        $web = [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'slug' => $this->slug,
            'short_bio' => $this->short_bio,
            'bio_content' => $this->bio_content,
            'approach_content' => $this->approach_content,
            'local_seo_title' => $this->local_seo_title,
            'local_seo_description' => $this->local_seo_description,
            'local_seo_h1' => $this->local_seo_h1,
            'is_local_seo_enabled' => (bool) $this->is_local_seo_enabled,
            'is_web_enabled' => (bool) $this->is_web_enabled,
            'effective_public_visibility' => $this->isEffectivelyVisible(),
            'hero_competency_ids' => $this->heroCompetencies->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'sections' => $this->sections
                ->whereIn('key', EquipeSectionDefinition::keys())
                ->sortBy('id')
                ->map(fn ($section) => [
                    'id' => $section->id,
                    'key' => $section->key,
                    'label' => EquipeSectionDefinition::DEFINITIONS[$section->key],
                    'is_active' => (bool) $section->is_active,
                    'title' => $section->key === 'services' ? $section->title : null,
                    'intro' => $section->key === 'services' ? $section->content : null,
                ])->values()->all(),
            'approach_principles' => $this->approachPrinciples->map(fn ($item) => [
                'id' => $item->id,
                'label' => $item->label,
                'icon_key' => $item->icon_key,
                'sort_order' => (int) $item->sort_order,
                'is_active' => (bool) $item->is_active,
            ])->values()->all(),
            'competencies' => $this->competencies->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'icon_key' => $item->icon_key,
                'sort_order' => (int) $item->sort_order,
                'is_active' => (bool) $item->is_active,
            ])->values()->all(),
            'scientific_activities' => $this->scientificActivities->map(fn ($item) => [
                'id' => $item->id,
                'contribution_type' => $item->contribution_type?->value ?? $item->contribution_type,
                'occurred_on' => optional($item->occurred_on)?->toDateString(),
                'year' => $item->year,
                'title' => $item->title,
                'source' => $item->source,
                'short_description' => $item->short_description,
                'url' => $item->url,
                'sort_order' => (int) $item->sort_order,
                'is_active' => (bool) $item->is_active,
            ])->values()->all(),
            'seo' => [
                'title' => $this->seo_title,
                'description' => $this->seo_description,
                'h1' => $this->seo_h1,
                'local_title' => $this->local_seo_title,
                'local_description' => $this->local_seo_description,
                'local_h1' => $this->local_seo_h1,
                'is_local_enabled' => (bool) $this->is_local_seo_enabled,
                'canonical_url' => $this->canonical_url,
                'robots' => $this->robots?->value ?? $this->robots,
                'og_title' => $this->og_title,
                'og_description' => $this->og_description,
                'og_image_path' => $this->og_image_path,
                'og_image_url' => PublicMediaUrl::fromPublicDisk($this->og_image_path ?: $avatarPath, $request),
                'twitter_title' => $this->twitter_title,
                'twitter_description' => $this->twitter_description,
                'twitter_image_path' => $this->twitter_image_path,
                'twitter_image_url' => PublicMediaUrl::fromPublicDisk($this->twitter_image_path, $request),
            ],
        ];

        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'master' => $professionalProjection,
            'professional' => $professionalProjection,
            'web_profile' => $web,
            'web' => $web,
            'is_configured' => true,
            'effective_public_visibility' => $this->isEffectivelyVisible(),
            // Transitional aliases for existing Core consumers.
            ...$web,
            'avatar_path' => $professional->avatar_path,
            'avatar_url' => PublicMediaUrl::fromPublicDisk($avatarPath, $request),
            'profile_image_path' => $this->profile_image_path,
            'profile_image_url' => PublicMediaUrl::fromPublicDisk($avatarPath, $request),
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_h1' => $this->seo_h1,
            'local_seo_title' => $this->local_seo_title,
            'local_seo_description' => $this->local_seo_description,
            'local_seo_h1' => $this->local_seo_h1,
            'is_local_seo_enabled' => (bool) $this->is_local_seo_enabled,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots?->value ?? $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image_path' => $this->og_image_path,
            'og_image_url' => PublicMediaUrl::fromPublicDisk($this->og_image_path ?: $avatarPath, $request),
            'twitter_title' => $this->twitter_title,
            'twitter_description' => $this->twitter_description,
            'twitter_image_path' => $this->twitter_image_path,
            'twitter_image_url' => PublicMediaUrl::fromPublicDisk($this->twitter_image_path, $request),
            'is_active' => (bool) $this->is_web_enabled,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    private function specialization($specialization): array
    {
        return [
            'id' => $specialization->id,
            'name' => $specialization->name,
            'slug' => $specialization->slug,
            'is_primary' => (bool) ($specialization->pivot?->is_primary ?? false),
            'sort_order' => (int) ($specialization->pivot?->sort_order ?? 0),
        ];
    }
}
