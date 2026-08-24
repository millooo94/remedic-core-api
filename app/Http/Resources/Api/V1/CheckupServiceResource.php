<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckupServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = $this->service;
        $specializations = $service?->relationLoaded('specializations')
            ? $service->specializations
                ->sortBy(fn ($specialization) => [
                    $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                    $specialization->id,
                ])
                ->values()
            : collect();
        $professionalLinks = $service?->relationLoaded('professionalServices')
            ? $service->professionalServices
            : collect();
        $includeProfessionals = $professionalLinks->contains(
            fn ($link): bool => $link->relationLoaded('professional'),
        );

        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'sort_order' => (int) $this->sort_order,
            'display_name' => $service?->display_name,
            'is_active' => (bool) $service?->is_active,
            'is_archived' => (bool) $service?->trashed(),
            'price_amount' => $service?->importo_prestazione,
            'duration_minutes' => $service?->default_duration_minutes,
            'areas' => $specializations->map(fn ($specialization): array => [
                'id' => (int) $specialization->id,
                'name' => $specialization->name,
                'slug' => $specialization->slug,
            ])->all(),
            'professionals_count' => $professionalLinks->pluck('professional_id')->unique()->count(),
            'professionals' => $this->when($includeProfessionals, fn () => $professionalLinks
                ->map(fn ($link) => $link->professional)
                ->filter()
                ->unique('id')
                ->values()
                ->map(fn ($professional): array => [
                    'id' => (int) $professional->id,
                    'full_name' => $professional->full_name,
                ])
                ->all()),
        ];
    }
}
