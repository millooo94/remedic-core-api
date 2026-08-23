<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items')
            ? $this->items->sortBy('sort_order')->values()
            : collect();
        $inactiveItemsCount = $items
            ->filter(fn ($item): bool => ! (bool) $item->service?->is_active)
            ->count();
        $areas = collect();
        $professionals = collect();
        $includeProfessionals = false;

        foreach ($items as $item) {
            $service = $item->service;
            if (! $service) {
                continue;
            }

            if ($service->relationLoaded('specializations')) {
                foreach ($service->specializations
                    ->sortBy(fn ($specialization) => [
                        $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                        $specialization->id,
                    ]) as $specialization) {
                    if (! $areas->has($specialization->id)) {
                        $areas->put($specialization->id, [
                            'id' => (int) $specialization->id,
                            'name' => $specialization->name,
                            'slug' => $specialization->slug,
                        ]);
                    }
                }
            }

            if ($service->relationLoaded('professionalServices')) {
                foreach ($service->professionalServices as $link) {
                    if (! $professionals->has($link->professional_id)) {
                        $professionals->put(
                            $link->professional_id,
                            $link->relationLoaded('professional') ? $link->professional : null,
                        );
                    }
                    $includeProfessionals = $includeProfessionals || $link->relationLoaded('professional');
                }
            }
        }

        return [
            'id' => $this->id,
            'kind' => 'checkup',
            'display_name' => $this->display_name,
            'price_amount' => $this->price_amount,
            'indicative_duration_minutes' => $this->indicative_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'is_operationally_available' => (bool) $this->is_active && $inactiveItemsCount === 0,
            'organizational_notes' => $this->organizational_notes,
            'featured_image_path' => $this->featured_image_path,
            'featured_image_url' => PublicMediaUrl::fromPublicDisk($this->featured_image_path, $request),
            'icon_path' => $this->icon_path,
            'icon_url' => PublicMediaUrl::fromPublicDisk($this->icon_path, $request),
            'items_count' => $items->count(),
            'items' => CheckupServiceResource::collection($this->whenLoaded('items')),
            'areas' => $areas->values()->all(),
            'professionals_count' => $professionals->keys()->count(),
            'professionals' => $this->when($includeProfessionals, fn () => $professionals
                ->filter()
                ->values()
                ->map(fn ($professional): array => [
                    'id' => (int) $professional->id,
                    'full_name' => $professional->full_name,
                ])
                ->all()),
            'has_inactive_items' => $inactiveItemsCount > 0,
            'inactive_items_count' => $inactiveItemsCount,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
