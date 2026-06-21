<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'professional_id' => $this->professional_id,
            'service_id' => $this->service_id,
            'starts_at' => optional($this->starts_at)->toIso8601String(),
            'ends_at' => optional($this->ends_at)->toIso8601String(),
            'status' => $this->status,
            'notes' => $this->notes,
            'patient' => $this->whenLoaded('patient', fn () => new PatientResource($this->patient)),
            'professional' => $this->whenLoaded('professional', fn () => new ProfessionalResource($this->professional)),
            'service' => $this->whenLoaded('service', fn () => new ServiceResource($this->service)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
