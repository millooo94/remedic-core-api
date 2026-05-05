<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Numbers\ScaledNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loadedPatients = $this->relationLoaded('patients') ? $this->patients : collect();
        $patientIds = $loadedPatients->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($patientIds === [] && $this->patient_id) {
            $patientIds = [(int) $this->patient_id];
        }
        $primaryPatient = $this->relationLoaded('patient') && $this->patient
            ? $this->patient
            : $loadedPatients->first();

        return [
            'id' => $this->id,
            'performed_at' => optional($this->performed_at)->toDateString(),
            'patient_id' => $this->patient_id,
            'patient_ids' => $patientIds,
            'professional_id' => $this->professional_id,
            'professional_name_snapshot' => $this->professional_name_snapshot ?: 'Non specificato',
            'category_name_snapshot' => $this->category_name_snapshot ?: 'Non specificato',
            'service_id' => $this->service_id,
            'service_name_snapshot' => $this->service_name_snapshot ?: 'Non specificato',
            'quantity' => (int) $this->quantity,
            'unit_amount' => $this->unit_amount,
            'total_amount' => $this->total_amount,
            'direct_cost' => $this->direct_cost,
            'net_divisible_amount' => ScaledNumber::fromScaledInteger(
                ScaledNumber::toScaledInteger($this->total_amount, 2, 'total_amount')
                - ScaledNumber::toScaledInteger($this->direct_cost, 2, 'direct_cost'),
                2,
            ),
            'calculation_mode' => $this->calculation_mode?->value ?? $this->calculation_mode,
            'split_mode' => $this->split_mode?->value ?? $this->split_mode ?? 'standard',
            'percentage_value' => $this->percentage_value,
            'fixed_amount' => $this->fixed_amount,
            'professional_amount' => $this->professional_amount,
            'center_amount' => $this->center_amount,
            'advanced_splits' => $this->whenLoaded('splits', fn () => $this->splits->map(fn ($split) => [
                'id' => $split->id,
                'subject_type' => $split->subject_type?->value ?? $split->subject_type,
                'professional_id' => $split->professional_id,
                'professional_name_snapshot' => $split->professional_name_snapshot,
                'amount' => $split->amount,
                'description' => $split->description,
                'sort_order' => (int) $split->sort_order,
                'professional' => $split->relationLoaded('professional') && $split->professional
                    ? new ProfessionalResource($split->professional)
                    : null,
            ])->values()),
            'payment_method' => $this->payment_method?->value ?? $this->payment_method,
            'payment_status' => $this->payment_status?->value ?? $this->payment_status ?? 'da_pagare',
            'is_invoiced' => (bool) $this->is_invoiced,
            'is_black' => (bool) $this->is_black,
            'is_promo' => (bool) $this->is_promo,
            'notes' => $this->notes,
            'patient' => $primaryPatient ? new PatientResource($primaryPatient) : null,
            'patients' => $this->whenLoaded('patients', fn () => PatientResource::collection($this->patients)),
            'professional' => $this->whenLoaded('professional', fn () => new ProfessionalResource($this->professional)),
            'service' => $this->whenLoaded('service', fn () => new ServiceResource($this->service)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
