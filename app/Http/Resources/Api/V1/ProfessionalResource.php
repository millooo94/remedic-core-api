<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Media\PublicMediaUrl;
use App\Support\Professionals\IbanFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $areas = $this->relationLoaded('areas')
            ? $this->areas->sortBy(fn ($area) => [
                $area->pivot?->sort_order ?? PHP_INT_MAX,
                $area->pivot?->id ?? PHP_INT_MAX,
                $area->id,
            ])->values()
            : collect();
        $areaNames = collect($areas)
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values();
        $areaIds = collect($areas)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($areaNames->isEmpty() && !empty($this->area_name)) {
            $areaNames->push((string) $this->area_name);
        }

        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type?->value ?? $this->subject_type,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'full_name' => $this->full_name,
            'area_name' => $this->area_name ?: ($areaNames->first() ?? null),
            'area_names' => $areaNames->all(),
            'area_ids' => $areaIds->all(),
            'email' => $this->email,
            'iban' => $this->iban,
            'iban_display' => IbanFormatter::format($this->iban),
            'avatar_url' => PublicMediaUrl::fromPublicDisk($this->avatar_path, $request),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
