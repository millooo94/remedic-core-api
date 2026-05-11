<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Enums\ConsentCategoryKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConsentCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $key = ConsentCategoryKey::tryFrom((string) $this->key);

        return [
            'id' => $this->id,
            'key' => $this->key,
            'key_label' => $key?->label(),
            'name' => $this->name,
            'description' => $this->description,
            'default_state' => (bool) $this->default_state,
            'is_required' => (bool) $this->is_required,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'services_count' => $this->whenCounted('services'),
            'is_locked' => $this->key === ConsentCategoryKey::NECESSARY->value,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
