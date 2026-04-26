<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class CashMovementFilters
{
    public function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('counterparty_name', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhere('movement_type', 'like', "%{$search}%");
                });
            })
            ->when($filters['cash_box_type'] ?? null, fn (Builder $builder, string $cashBoxType) => $builder->where('cash_box_type', $cashBoxType))
            ->when($filters['movement_type'] ?? null, fn (Builder $builder, string $movementType) => $builder->where('movement_type', $movementType))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('movement_date', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('movement_date', '<=', $dateTo));
    }

    public function applySort(Builder $query, ?string $sort): Builder
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $field = ltrim((string) $sort, '-');

        return match ($field) {
            'movement_date', 'movement_type', 'cash_box_type', 'counterparty_name', 'amount', 'balance_after' => $query->orderBy($field, $direction)->orderBy('id', $direction),
            default => $query->orderBy('movement_date', 'desc')->orderBy('id', 'desc'),
        };
    }
}
