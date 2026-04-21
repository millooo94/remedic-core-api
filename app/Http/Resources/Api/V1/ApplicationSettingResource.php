<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reminder_email' => $this->reminder_email,
            'quick_percentages' => $this->quick_percentages,
            'general_preferences' => $this->general_preferences,
            'updated_by' => $this->updated_by,
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
