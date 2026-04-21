<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfessionalServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'service_id' => $this->service_id,
            'duration_minutes' => $this->duration_minutes,
            'price_amount' => $this->price_amount,
            'is_visible_public' => (bool) $this->is_visible_public,
            'is_bookable_online' => (bool) $this->is_bookable_online,
            'source_platform' => $this->source_platform,
            'source_notes' => $this->source_notes,
            'is_active' => (bool) $this->is_active,
            'professional' => $this->whenLoaded('professional', fn () => new ProfessionalResource($this->professional)),
        ];
    }
}
