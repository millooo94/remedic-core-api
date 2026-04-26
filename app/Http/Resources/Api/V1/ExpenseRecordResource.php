<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fallbackMonthDate = ($this->competence_year && $this->competence_month)
            ? sprintf('%04d-%02d-01', $this->competence_year, $this->competence_month)
            : null;

        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'expense_template_id' => $this->expense_template_id,
            'source_performance_record_id' => $this->source_performance_record_id,
            'source' => $this->source ?? 'manual',
            'generation_key' => $this->generation_key,
            'expense_date' => optional($this->expense_date)->toDateString(),
            'competence_start_date' => optional($this->competence_start_date)->toDateString() ?? $fallbackMonthDate,
            'competence_end_date' => optional($this->competence_end_date)->toDateString() ?? $fallbackMonthDate,
            'competence_months_count' => $this->competence_months_count ?? 1,
            'competence_month' => $this->competence_month,
            'competence_year' => $this->competence_year,
            'description' => $this->description,
            'type' => $this->type?->value ?? $this->type,
            'amount' => $this->amount,
            'supplier' => $this->supplier,
            'payment_status' => $this->payment_status?->value ?? $this->payment_status,
            'notes' => $this->notes,
            'competence_allocations' => $this->whenLoaded('competenceAllocations', fn () => $this->competenceAllocations->map(fn ($allocation) => [
                'competence_date' => optional($allocation->competence_date)->toDateString(),
                'competence_month' => $allocation->competence_month,
                'competence_year' => $allocation->competence_year,
                'allocated_amount' => $allocation->allocated_amount,
            ])->values()),
            'category' => $this->whenLoaded('category', fn () => new ExpenseCategoryResource($this->category)),
            'template' => $this->whenLoaded('template', fn () => new ExpenseTemplateResource($this->template)),
        ];
    }
}
