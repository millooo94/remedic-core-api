<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoogleReviewRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'performance_record_id' => $this->performance_record_id,
            'professional_id' => $this->professional_id,
            'specialization_id' => $this->specialization_id,
            'patient_name' => $this->patient_name,
            'patient_phone' => $this->patient_phone,
            'professional_name' => $this->professional_name,
            'professional_title' => $this->professional_title,
            'specialization_name' => $this->specialization_name,
            'review_url' => $this->review_url,
            'message_body' => $this->message_body,
            'status' => $this->status,
            'status_label' => match ($this->status) {
                'pending' => 'In attesa',
                'sent' => 'Inviato',
                'excluded' => 'Escluso',
                'cancelled' => 'Annullato',
                'error' => 'Errore',
                default => ucfirst((string) $this->status),
            },
            'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'excluded_at' => optional($this->excluded_at)->toIso8601String(),
            'error_message' => $this->error_message,
            'provider_status' => $this->provider_status,
            'provider_message_id' => $this->provider_message_id,
            'provider_response' => $this->provider_response,
            'template_payload' => $this->template_payload,
            'performance_record' => $this->whenLoaded('performanceRecord', fn () => [
                'id' => $this->performanceRecord?->id,
                'performed_at' => optional($this->performanceRecord?->performed_at)->toDateString(),
                'service_name_snapshot' => $this->performanceRecord?->service_name_snapshot,
            ]),
            'patient' => $this->whenLoaded('patient', fn () => $this->patient ? new PatientResource($this->patient) : null),
            'professional' => $this->whenLoaded('professional', fn () => $this->professional ? new ProfessionalResource($this->professional) : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
