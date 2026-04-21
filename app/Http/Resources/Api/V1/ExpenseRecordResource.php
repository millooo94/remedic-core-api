<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'expense_template_id' => $this->expense_template_id,
            'source' => $this->source ?? 'manual',
            'generation_key' => $this->generation_key,
            'expense_date' => optional($this->expense_date)->toDateString(),
            'competence_month' => $this->competence_month,
            'competence_year' => $this->competence_year,
            'description' => $this->description,
            'type' => $this->type?->value ?? $this->type,
            'amount' => $this->amount,
            'supplier' => $this->supplier,
            'payment_status' => $this->payment_status?->value ?? $this->payment_status,
            'notes' => $this->notes,
            'category' => $this->whenLoaded('category', fn () => new ExpenseCategoryResource($this->category)),
            'template' => $this->whenLoaded('template', fn () => new ExpenseTemplateResource($this->template)),
        ];
    }
}
