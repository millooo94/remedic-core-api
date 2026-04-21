<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'recipient_email' => $this->recipient_email,
            'subject' => $this->subject,
            'body' => $this->body,
            'frequency' => $this->frequency,
            'day_of_month' => $this->day_of_month,
            'day_of_week' => $this->day_of_week,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'last_sent_at' => optional($this->last_sent_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}

