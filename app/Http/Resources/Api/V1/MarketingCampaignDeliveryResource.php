<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingCampaignDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_campaign_id' => $this->marketing_campaign_id,
            'patient_id' => $this->patient_id,
            'channel' => $this->channel,
            'is_test' => (bool) $this->is_test,
            'target_name' => $this->target_name,
            'target_value' => $this->target_value,
            'delivery_status' => $this->delivery_status,
            'provider_message_id' => $this->provider_message_id,
            'provider_status' => $this->provider_status,
            'error_message' => $this->error_message,
            'provider_response' => $this->provider_response,
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'patient' => $this->whenLoaded('patient', fn () => new PatientResource($this->patient)),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
