<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'type' => 'fixed',
            'recurrence' => $this->recurrence?->value ?? $this->recurrence,
            'default_amount' => $this->default_amount,
            'start_date' => optional($this->start_date)->toDateString(),
            'end_date' => optional($this->end_date)->toDateString(),
            'day_of_generation' => $this->day_of_generation,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'next_generation_date' => null,
            'category' => $this->whenLoaded('category', fn () => new ExpenseCategoryResource($this->category)),
        ];
    }
}
