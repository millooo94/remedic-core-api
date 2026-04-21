<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'default_duration_minutes' => $this->default_duration_minutes,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'category' => $this->whenLoaded('category', fn () => new ServiceCategoryResource($this->category)),
            'aliases' => ServiceAliasResource::collection($this->whenLoaded('aliases')),
            'professional_services' => ProfessionalServiceResource::collection($this->whenLoaded('professionalServices')),
        ];
    }
}
