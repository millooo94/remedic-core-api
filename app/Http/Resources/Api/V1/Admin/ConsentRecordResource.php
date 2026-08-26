<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'configuration_version' => $this->configuration_version,
            'necessary' => (bool) $this->necessary,
            'preferences' => (bool) $this->preferences,
            'statistics' => (bool) $this->statistics,
            'marketing' => (bool) $this->marketing,
            'consented_at' => optional($this->consented_at)?->toIso8601String(),
            'last_updated_at' => optional($this->last_updated_at)?->toIso8601String(),
            'requires_renewal' => $this->when(isset($this->current_configuration_version), $this->configuration_version !== $this->current_configuration_version),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event): array => [
                'event_type' => $event->event_type->value,
                'configuration_version' => $event->configuration_version,
                'necessary' => (bool) $event->necessary,
                'preferences' => (bool) $event->preferences,
                'statistics' => (bool) $event->statistics,
                'marketing' => (bool) $event->marketing,
                'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
            ])->values()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
