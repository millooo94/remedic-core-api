<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $specializations = $this->whenLoaded('specializations', fn () => $this->specializations
            ->sortBy(fn ($specialization) => [
                $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                $specialization->id,
            ])
            ->values());

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'canonical_name' => $this->canonical_name,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'importo_prestazione' => $this->importo_prestazione,
            'default_duration_minutes' => $this->default_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
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
                    'is_primary' => (bool) ($specialization->pivot?->is_primary ?? false),
                    'sort_order' => (int) ($specialization->pivot?->sort_order ?? 0),
                ])->all()),
        ];
    }
}
