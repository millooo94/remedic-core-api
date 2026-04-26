<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'performed_at' => optional($this->performed_at)->toDateString(),
            'professional_id' => $this->professional_id,
            'professional_name_snapshot' => $this->professional_name_snapshot ?: 'Non specificato',
            'category_name_snapshot' => $this->category_name_snapshot ?: 'Non specificato',
            'service_id' => $this->service_id,
            'service_name_snapshot' => $this->service_name_snapshot ?: 'Non specificato',
            'quantity' => (int) $this->quantity,
            'unit_amount' => $this->unit_amount,
            'total_amount' => $this->total_amount,
            'calculation_mode' => $this->calculation_mode?->value ?? $this->calculation_mode,
            'percentage_value' => $this->percentage_value,
            'fixed_amount' => $this->fixed_amount,
            'professional_amount' => $this->professional_amount,
            'center_amount' => $this->center_amount,
            'payment_method' => $this->payment_method?->value ?? $this->payment_method,
            'is_invoiced' => (bool) $this->is_invoiced,
            'is_black' => (bool) $this->is_black,
            'notes' => $this->notes,
            'professional' => $this->whenLoaded('professional', fn () => new ProfessionalResource($this->professional)),
            'service' => $this->whenLoaded('service', fn () => new ServiceResource($this->service)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
