<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyBookingStatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'bookings_count' => $this->bookings_count,
            'cancellations_count' => $this->cancellations_count,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'submitter' => $this->whenLoaded('submitter', fn () => ['id' => $this->submitter?->id, 'full_name' => $this->submitter?->full_name]),
        ];
    }
}
