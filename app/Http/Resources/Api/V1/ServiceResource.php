<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use App\Support\Services\PrimarySpecializationResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primarySpecialization = app(PrimarySpecializationResolver::class)->resolve($this->resource);
        $specializations = $this->whenLoaded('specializations', fn () => $this->specializations
            ->sortBy(fn ($specialization) => [
                $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                $specialization->id,
            ])
            ->values());

        return [
            'id' => $this->id,
            'kind' => 'service',
            'category_id' => $this->category_id,
            'classification' => $this->classification?->value,
            'canonical_name' => $this->canonical_name,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'importo_prestazione' => $this->importo_prestazione,
            'default_duration_minutes' => $this->default_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->trashed(),
            'notes' => $this->notes,
            'featured_image_path' => $this->featured_image_path,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
            'inherited_icon_path' => $primarySpecialization?->icon_path,
            'inherited_icon_url' => PublicMediaUrl::fromPublicDisk($primarySpecialization?->icon_path, $request),
            'category' => $this->whenLoaded('category', fn () => new ServiceCategoryResource($this->category)),
            'aliases' => ServiceAliasResource::collection($this->whenLoaded('aliases')),
            'professional_services' => ProfessionalServiceResource::collection($this->whenLoaded('professionalServices')),
            'specialization_ids' => $this->whenLoaded('specializations', fn () => $this->specializations
                ->sortBy(fn ($specialization) => [
                    $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                    $specialization->id,
                ])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all()),
            'specializations' => $this->whenLoaded('specializations', fn () => $this->specializations
                ->sortBy(fn ($specialization) => [
                    $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                    $specialization->id,
                ])
                ->values()
                ->map(fn ($specialization) => [
                    'id' => $specialization->id,
                    'name' => $specialization->name,
                    'slug' => $specialization->slug,
                    'color_hex' => $specialization->color_hex,
                    'professional_title_male' => $specialization->professional_title_male,
                    'professional_title_female' => $specialization->professional_title_female,
                    'icon_path' => $specialization->icon_path,
                    'icon_url' => PublicMediaUrl::fromPublicDisk($specialization->icon_path, $request),
                    'is_primary' => (bool) ($specialization->pivot?->is_primary ?? false),
                    'sort_order' => (int) ($specialization->pivot?->sort_order ?? 0),
                ])->all()),
        ];
    }
}
