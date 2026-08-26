<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
            'application_type' => ['key' => $this->application_type_key_snapshot, 'label' => $this->application_type_name_snapshot],
            'has_cv' => filled($this->cv_path),
            'status' => $this->status?->value ?? $this->status,
            'status_label' => $this->status?->label(),
            'is_unopened' => is_null($this->resource->first_opened_at),
            'first_opened_at' => optional($this->first_opened_at)->toIso8601String(),
            'locale' => $this->locale,
            'privacy_consent_at' => optional($this->privacy_consent_at)->toIso8601String(),
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
