<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NewsletterCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internal_name' => $this->internal_name,
            'subject' => $this->subject,
            'preheader' => $this->preheader,
            'content' => $this->content,
            'status' => $this->status->value,
            'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
            'sending_started_at' => optional($this->sending_started_at)->toIso8601String(),
            'sent_at' => optional($this->sent_at)->toIso8601String(),
            'recipient_count' => (int) $this->recipient_count,
            'sent_count' => (int) $this->sent_count,
            'failed_count' => (int) $this->failed_count,
            'suppressed_count' => (int) $this->suppressed_count,
            'last_test_sent_at' => optional($this->last_test_sent_at)->toIso8601String(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'launched_by' => $this->launched_by,
            'creator_name' => $this->creator?->full_name,
            'updater_name' => $this->updater?->full_name,
            'launcher_name' => $this->launcher?->full_name,
            'deliveries' => NewsletterCampaignDeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
