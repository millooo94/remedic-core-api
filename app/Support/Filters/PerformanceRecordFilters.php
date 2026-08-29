<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class PerformanceRecordFilters
{
    public function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('professional_name_snapshot', 'like', "%{$search}%")
                        ->orWhereHas('patients', function (Builder $patientQuery) use ($search): void {
                            $patientQuery
                                ->where('full_name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        })
                        ->orWhere('category_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('service_name_snapshot', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%");
                });
            })
            ->when($filters['patient_id'] ?? null, fn (Builder $builder, mixed $patientId) => $builder->whereHas('patients', fn (Builder $nested) => $nested->where('patients.id', $patientId)))
            ->when($filters['only_unreconciled'] ?? null, fn (Builder $builder) => $builder->doesntHave('patients'))
            ->when($filters['professional_id'] ?? null, fn (Builder $builder, mixed $professionalId) => $builder->where('professional_id', $professionalId))
            ->when($filters['area_name'] ?? null, fn (Builder $builder, string $areaName) => $builder->where('category_name_snapshot', $areaName))
            ->when($filters['service_id'] ?? null, fn (Builder $builder, mixed $serviceId) => $builder->where('service_id', $serviceId))
            ->when($filters['invoice_filter'] ?? null, function (Builder $builder, string $invoiceFilter) use ($filters): void {
                match ($invoiceFilter) {
                    'invoiced' => $builder->where('is_invoiced', true),
                    'not_invoiced' => ($filters['fiscal_filter'] ?? null) === 'black'
                        ? $builder->where('is_black', true)
                        : (($filters['fiscal_filter'] ?? null) === 'provvigione'
                            ? $builder->where('is_provvigione', true)
                        : $builder
                            ->where('is_invoiced', false)
                            ->where('is_black', false)
                            ->where('is_provvigione', false)),
                    default => null,
                };
            })
            ->when($filters['liquidation_filter'] ?? null, function (Builder $builder, string $liquidationFilter): void {
                match ($liquidationFilter) {
                    'liquidated' => $builder->where('payment_status', 'pagata'),
                    'not_liquidated' => $builder->where('payment_status', 'da_pagare'),
                    default => null,
                };
            })
            ->when($filters['fiscal_filter'] ?? null, function (Builder $builder, string $fiscalFilter): void {
                match ($fiscalFilter) {
                    'white' => $builder->where('is_black', false)->where('is_provvigione', false),
                    'black' => $builder->where('is_black', true),
                    'provvigione' => $builder->where('is_provvigione', true),
                    default => null,
                };
            })
            ->when($filters['month'] ?? null, fn (Builder $builder, int $month) => $builder->whereMonth('performed_at', $month))
            ->when($filters['year'] ?? null, fn (Builder $builder, int $year) => $builder->whereYear('performed_at', $year))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $dateFrom) => $builder->whereDate('performed_at', '>=', $dateFrom))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $dateTo) => $builder->whereDate('performed_at', '<=', $dateTo));
    }

    public function applySort(Builder $query, ?string $sort): Builder
    {
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $field = ltrim((string) $sort, '-');

        return match ($field) {
            'performed_at', 'professional_name_snapshot', 'category_name_snapshot', 'service_name_snapshot', 'quantity', 'total_amount', 'professional_amount', 'center_amount', 'payment_status' => $query->orderBy($field, $direction),
            default => $query->orderBy('created_at', 'desc')->orderBy('id', 'desc'),
        };
    }
}
