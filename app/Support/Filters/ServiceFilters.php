<?php

namespace App\Support\Filters;

use Illuminate\Database\Eloquent\Builder;

class ServiceFilters
{
    public function apply(Builder $query, array $filters): Builder
    {
        match ($filters['archive_state'] ?? 'active') {
            'archived' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => null,
        };

        return $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $terms = preg_split('/\s+/', trim($search)) ?: [];
                $terms = array_values(array_filter($terms, fn (string $term) => $term !== ''));

                $builder->where(function (Builder $nested) use ($search, $terms): void {
                    $nested
                        ->where('canonical_name', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%")
                        ->orWhereHas('aliases', fn (Builder $aliasQuery) => $aliasQuery->where('alias_name', 'like', "%{$search}%"))
                        ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('specializations', fn (Builder $specializationQuery) => $specializationQuery->where('name', 'like', "%{$search}%"));

                    if ($terms !== []) {
                        $nested->orWhereHas('professionalServices.professional', function (Builder $professionalQuery) use ($terms): void {
                            $professionalQuery->where(function (Builder $nameQuery) use ($terms): void {
                                foreach ($terms as $term) {
                                    $nameQuery->where(function (Builder $singleTermQuery) use ($term): void {
                                        $singleTermQuery
                                            ->where('full_name', 'like', "%{$term}%")
                                            ->orWhere('company_name', 'like', "%{$term}%")
                                            ->orWhere('first_name', 'like', "%{$term}%")
                                            ->orWhere('last_name', 'like', "%{$term}%");
                                    });
                                }
                            });
                        });
                    }
                });
            })
            ->when($filters['category_id'] ?? null, fn (Builder $builder, mixed $categoryId) => $builder->where('category_id', $categoryId))
            ->when($filters['category_name'] ?? null, function (Builder $builder, mixed $categoryName): void {
                $normalized = mb_strtolower(trim((string) $categoryName));
                if ($normalized === '') {
                    return;
                }

                $builder->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]));
            })
            ->when($filters['specialization_name'] ?? null, function (Builder $builder, mixed $specializationName): void {
                $normalized = mb_strtolower(trim((string) $specializationName));
                if ($normalized === '') {
                    return;
                }

                $builder->whereHas('specializations', fn (Builder $specializationQuery) => $specializationQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalized]));
            })
            ->when($filters['professional_id'] ?? null, fn (Builder $builder, mixed $professionalId) => $builder->whereHas('professionalServices', fn (Builder $linkQuery) => $linkQuery->where('professional_id', $professionalId)))
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', fn (Builder $builder) => $builder->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)));
    }
}
