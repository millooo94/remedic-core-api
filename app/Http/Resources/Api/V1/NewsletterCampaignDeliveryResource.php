<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsletterCampaignDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'newsletter_campaign_id' => $this->newsletter_campaign_id,
            'newsletter_subscriber_id' => $this->newsletter_subscriber_id,
            'email_snapshot' => $this->email_snapshot,
            'delivery_status' => $this->delivery_status->value,
            'error_message' => $this->error_message,
            'queued_at' => optional($this->queued_at)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'failed_at' => optional($this->failed_at)->toIso8601String(),
            'suppressed_at' => optional($this->suppressed_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
