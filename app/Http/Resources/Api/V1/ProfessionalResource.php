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
        $specializations = $this->relationLoaded('specializations')
            ? $this->specializations
                ->sortBy(fn ($specialization) => [
                    ($specialization->pivot?->is_primary ?? false) ? 0 : 1,
                    $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                    $specialization->id,
                ])
                ->values()
            : collect();

        $areas = $this->relationLoaded('areas')
            ? $this->areas
                ->sortBy(fn ($area) => [
                    $area->pivot?->sort_order ?? PHP_INT_MAX,
                    $area->pivot?->id ?? PHP_INT_MAX,
                    $area->id,
                ])
                ->values()
            : collect();

        $areaNames = $specializations
            ->pluck('name')
            ->filter()
            ->map(fn ($name) => (string) $name)
            ->values();

        $areaIds = $specializations
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($areaNames->isEmpty()) {
            $areaNames = $areas
                ->pluck('name')
                ->filter()
                ->map(fn ($name) => (string) $name)
                ->values();

            $areaIds = $areas
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();
        }

        if ($areaNames->isEmpty() && ! empty($this->area_name)) {
            $areaNames->push((string) $this->area_name);
        }

        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type?->value ?? $this->subject_type,
            'gender' => $this->gender?->value ?? $this->gender ?? 'unspecified',
            'honorific_prefix' => $this->honorific_prefix,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'full_name' => $this->full_name,
            'birth_date' => optional($this->birth_date)?->toDateString(),
            'birth_place' => $this->birth_place,
            'area_name' => $this->area_name ?: ($areaNames->first() ?? null),
            'area_names' => $areaNames->all(),
            'area_ids' => $areaIds->all(),
            'email' => $this->email,
            'title_prefix' => $this->honorific_prefix,
            'iban' => $this->iban,
            'iban_display' => IbanFormatter::format($this->iban),
            'avatar_path' => $this->avatar_path,
            'avatar_url' => PublicMediaUrl::fromPublicDisk($this->avatar_path, $request),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'specialization_ids' => $areaIds->all(),
            'specializations' => $this->whenLoaded('specializations', fn () => $specializations
                ->map(fn ($specialization) => [
                    'id' => $specialization->id,
                    'name' => $specialization->name,
                    'slug' => $specialization->slug,
                    'color_hex' => $specialization->color_hex,
                    'professional_title_male' => $specialization->professional_title_male,
                    'professional_title_female' => $specialization->professional_title_female,
                    'is_primary' => (bool) ($specialization->pivot?->is_primary ?? false),
                    'sort_order' => (int) ($specialization->pivot?->sort_order ?? 0),
                ])->all()),
            'degrees' => $this->whenLoaded('degrees', fn () => $this->degrees->map(fn ($degree) => [
                'id' => $degree->id,
                'title' => $degree->title,
                'awarded_on' => optional($degree->awarded_on)?->toDateString(),
                'sort_order' => (int) $degree->sort_order,
            ])->values()->all()),
            'academic_specializations' => $this->whenLoaded('academicSpecializations', fn () => $this->academicSpecializations->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'awarded_on' => optional($item->awarded_on)?->toDateString(),
                'sort_order' => (int) $item->sort_order,
            ])->values()->all()),
            'board_registrations' => $this->whenLoaded('boardRegistrations', fn () => $this->boardRegistrations->map(fn ($item) => [
                'id' => $item->id,
                'board_name' => $item->board_name,
                'registration_number' => $item->registration_number,
                'registered_on' => optional($item->registered_on)?->toDateString(),
                'sort_order' => (int) $item->sort_order,
            ])->values()->all()),
            'career_experiences' => $this->whenLoaded('careerExperiences', fn () => $this->careerExperiences->map(fn ($item) => [
                'id' => $item->id,
                'year_from' => (int) $item->year_from,
                'year_to' => $item->year_to !== null ? (int) $item->year_to : null,
                'is_current' => (bool) $item->is_current,
                'title' => $item->title,
                'organization' => $item->organization,
                'description' => $item->description,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all()),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
