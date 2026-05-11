<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\ConsentEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentPreferenceChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $eventType = $this->event_type instanceof ConsentEventType
            ? $this->event_type
            : ConsentEventType::tryFrom((string) $this->event_type);

        return [
            'id' => $this->id,
            'consent_record_id' => $this->consent_record_id,
            'consent_record' => $this->whenLoaded('consentRecord', fn () => $this->consentRecord ? [
                'id' => $this->consentRecord->id,
                'consent_uuid' => $this->consentRecord->consent_uuid,
                'status' => $this->consentRecord->status(),
            ] : null),
            'policy_version' => $this->whenLoaded('consentRecord', fn () => $this->consentRecord?->policyVersion ? [
                'id' => $this->consentRecord->policyVersion->id,
                'version' => $this->consentRecord->policyVersion->version,
            ] : null),
            'event_type' => $eventType?->value ?? $this->event_type,
            'event_type_label' => $eventType?->label(),
            'payload' => $this->payload ?? [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
