<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\ConsentExecutionMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $mode = $this->execution_mode instanceof ConsentExecutionMode
            ? $this->execution_mode
            : ConsentExecutionMode::tryFrom((string) $this->execution_mode);

        return [
            'id' => $this->id,
            'consent_category_id' => $this->consent_category_id,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'key' => $this->category?->key,
            ]),
            'key' => $this->key,
            'name' => $this->name,
            'provider' => $this->provider,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'privacy_url' => $this->privacy_url,
            'cookie_names' => $this->cookie_names ?? [],
            'retention_period' => $this->retention_period,
            'legal_basis_hint' => $this->legal_basis_hint,
            'execution_mode' => $mode?->value ?? $this->execution_mode,
            'execution_mode_label' => $mode?->label(),
            'public_config' => $this->public_config ?? [],
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
