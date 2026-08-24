<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class CheckupFilters
{
    public function apply(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));

        match ($filters['archive_state'] ?? 'active') {
            'archived' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => null,
        };

        return $query
            ->when($search !== '', function (Builder $builder) use ($search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('display_name', 'like', "%{$search}%")
                        ->orWhereHas('items.service', fn (Builder $serviceQuery) => $serviceQuery->where('display_name', 'like', "%{$search}%"));
                });
            })
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', function (Builder $builder) use ($filters): void {
                $builder->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when($filters['specialization_name'] ?? null, function (Builder $builder, mixed $specializationName): void {
                $normalized = mb_strtolower(trim((string) $specializationName));
                if ($normalized === '') {
                    return;
                }

                $builder->whereHas('items.service.specializations', fn (Builder $specializationQuery) => $specializationQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]));
            })
            ->when($filters['professional_id'] ?? null, function (Builder $builder, mixed $professionalId): void {
                $builder->whereHas('items.service.professionalServices', fn (Builder $linkQuery) => $linkQuery
                    ->where('professional_id', $professionalId)
                    ->where('is_active', true)
                    ->whereHas('professional', fn (Builder $professionalQuery) => $professionalQuery->where('is_active', true)));
            });
    }
}
