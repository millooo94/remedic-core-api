<?php

namespace App\Support\Services;

use App\Models\Service;
use App\Models\Specialization;

class PrimarySpecializationResolver
{
    public function resolve(Service $service): ?Specialization
    {
        $specializations = $service->relationLoaded('specializations')
            ? $service->specializations
            : $service->specializations()->get();

        return $specializations
            ->sortBy(fn (Specialization $specialization): array => [
                ($specialization->pivot?->is_primary ?? false) ? 0 : 1,
                $specialization->pivot?->sort_order ?? PHP_INT_MAX,
                $specialization->id,
            ])
            ->first();
    }
}
