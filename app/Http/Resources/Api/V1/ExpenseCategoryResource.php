<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type?->value ?? $this->type,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
            'records_count' => $this->whenCounted('records'),
            'templates_count' => $this->whenCounted('templates'),
        ];
    }
}
