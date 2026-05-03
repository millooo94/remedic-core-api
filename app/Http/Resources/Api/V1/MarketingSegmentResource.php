<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingSegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'segment_type' => $this->segment_type ?? 'filter_based',
            'filters' => $this->filters ?? [],
            'last_preview_count' => (int) ($this->last_preview_count ?? 0),
            'manual_recipients_count' => (int) ($this->manual_recipients_count ?? $this->manualRecipients?->count() ?? 0),
            'manual_recipients' => $this->whenLoaded('manualRecipients', fn () => $this->manualRecipients->map(fn ($recipient) => [
                'id' => $recipient->id,
                'patient_id' => $recipient->patient_id,
                'patient_name' => $recipient->patient?->full_name,
                'normalized_phone' => $recipient->normalized_phone,
                'original_value' => $recipient->original_value,
            ])->values()->all()),
            'is_active' => (bool) $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'creator_name' => $this->creator?->full_name,
            'updater_name' => $this->updater?->full_name,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
