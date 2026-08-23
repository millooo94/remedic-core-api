<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'marketing_segment_id' => $this->marketing_segment_id,
            'channel' => $this->channel,
            'template_key' => $this->template_key,
            'subject' => $this->subject,
            'message' => $this->message,
            'status' => $this->status,
            'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
            'dispatched_at' => optional($this->dispatched_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'recipients_count' => (int) ($this->recipients_count ?? 0),
            'sent_count' => (int) ($this->sent_count ?? 0),
            'failed_count' => (int) ($this->failed_count ?? 0),
            'excluded_count' => (int) ($this->excluded_count ?? 0),
            'last_test_sent_at' => optional($this->last_test_sent_at)->toIso8601String(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'launched_by' => $this->launched_by,
            'creator_name' => $this->creator?->full_name,
            'launcher_name' => $this->launcher?->full_name,
            'segment' => $this->whenLoaded('segment', fn () => new MarketingSegmentResource($this->segment)),
            'deliveries' => MarketingCampaignDeliveryResource::collection($this->whenLoaded('deliveries')),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
