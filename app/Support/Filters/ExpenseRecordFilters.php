<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class ExpenseRecordFilters
{
    public function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('description', 'like', "%{$search}%")
                        ->orWhere('supplier', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->when($filters['type'] ?? null, fn (Builder $builder, string $type) => $builder->where('type', $type))
            ->when($filters['expense_category_id'] ?? null, fn (Builder $builder, mixed $categoryId) => $builder->where('expense_category_id', $categoryId))
            ->when($filters['payment_status'] ?? null, fn (Builder $builder, string $status) => $builder->where('payment_status', $status))
            ->when($filters['month'] ?? null, function (Builder $builder, int $month) use ($filters): void {
                $builder->whereHas('competenceAllocations', function (Builder $allocationQuery) use ($month, $filters): void {
                    $allocationQuery->where('competence_month', $month);
                    if (! empty($filters['year'])) {
                        $allocationQuery->where('competence_year', (int) $filters['year']);
                    }
                });
            })
            ->when(($filters['year'] ?? null) && ! ($filters['month'] ?? null), fn (Builder $builder, int $year) => $builder->whereHas('competenceAllocations', fn (Builder $allocationQuery) => $allocationQuery->where('competence_year', $year)))
            ->when($filters['competence_date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereHas('competenceAllocations', fn (Builder $allocationQuery) => $allocationQuery->whereDate('competence_date', '>=', $dateFrom)))
            ->when($filters['competence_date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereHas('competenceAllocations', fn (Builder $allocationQuery) => $allocationQuery->whereDate('competence_date', '<=', $dateTo)))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('expense_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('expense_date', '<=', $dateTo));
    }

    public function applySort(Builder $query, ?string $sort): Builder
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $field = ltrim((string) $sort, '-');

        return match ($field) {
            'expense_date', 'competence_start_date', 'competence_end_date', 'competence_year', 'competence_month', 'description', 'amount', 'type', 'payment_status' => $query->orderBy($field, $direction),
            default => $query->orderBy('expense_date', 'desc')->orderBy('id', 'desc'),
        };
    }
}
