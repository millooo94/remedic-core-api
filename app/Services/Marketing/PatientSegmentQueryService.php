<?php

namespace App\Services\Marketing;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientSegmentQueryService
{
    public function __construct(
        private readonly PatientGeocodingService $patientGeocodingService,
    ) {}

    public function decorateWithMarketingMetrics(Builder $query): Builder
    {
        return $query
            ->select('patients.*')
            ->withCount('performanceRecords as performances_count')
            ->withMax('performanceRecords as last_visit_at', 'performed_at');
    }

    public function hydrateVisitedSpecializations(iterable $patients): void
    {
        $patientCollection = $patients instanceof Collection
            ? $patients
            : collect(is_array($patients) ? $patients : iterator_to_array($patients));

        $patientIds = $patientCollection
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($patientIds->isEmpty()) {
            $patientCollection->each(fn ($patient) => $patient->setAttribute('visited_specializations', []));

            return;
        }

        $specializationsByPatient = DB::table('patient_performance_record')
            ->join('performance_records', 'performance_records.id', '=', 'patient_performance_record.performance_record_id')
            ->select([
                'patient_performance_record.patient_id',
                'performance_records.category_name_snapshot',
            ])
            ->whereIn('patient_performance_record.patient_id', $patientIds)
            ->whereNotNull('performance_records.category_name_snapshot')
            ->where('performance_records.category_name_snapshot', '<>', '')
            ->distinct()
            ->orderBy('performance_records.category_name_snapshot')
            ->get()
            ->groupBy('patient_id')
            ->map(fn (Collection $rows) => $rows
                ->pluck('category_name_snapshot')
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique(fn (string $value) => mb_strtolower($value))
                ->values()
                ->all());

        $patientCollection->each(function ($patient) use ($specializationsByPatient): void {
            $patient->setAttribute('visited_specializations', $specializationsByPatient->get($patient->id, []));
        });
    }

    public function applySegmentFilters(Builder $query, array $rules): Builder
    {
        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $field = (string) ($rule['field'] ?? '');
            $operator = (string) ($rule['operator'] ?? 'eq');
            $value = $rule['value'] ?? null;

            if ($field === '') {
                continue;
            }

            match ($field) {
                'age' => $this->applyAgeRule($query, $operator, $value),
                'birth_date' => $this->applyBirthDateRule($query, $operator, $value),
                'year_of_birth' => $this->applyYearOfBirthRule($query, $operator, $value),
                'sex' => $this->applySexRule($query, $operator, $value),
                'has_phone' => $this->applyPresenceRule($query, 'phone', $operator),
                'has_email' => $this->applyPresenceRule($query, 'email', $operator),
                'contactable_sms' => $this->applyBooleanRule($query, 'contactable_sms', $operator),
                'contactable_email' => $this->applyBooleanRule($query, 'contactable_email', $operator),
                'excluded_from_campaigns' => $this->applyBooleanRule($query, 'excluded_from_campaigns', $operator),
                'last_visit_date' => $this->applyLastVisitRule($query, $operator, $value),
                'inactive_months' => $this->applyInactiveMonthsRule($query, $operator, $value),
                'specialization' => $this->applySpecializationRule($query, $operator, $value),
                'service' => $this->applyServiceRule($query, $operator, $value, (string) ($rule['display_value'] ?? '')),
                'visits_count' => $this->applyVisitsCountRule($query, $operator, $value),
                'residence_city' => $this->applyResidenceCityRule($query, $operator, $value),
                'distance_from_remedic_km' => $this->applyDistanceFromRemedicRule($query, $operator, $value),
                default => null,
            };
        }

        return $query;
    }

    public function applyChannelEligibility(Builder $query, string $channel): Builder
    {
        $query->where('excluded_from_campaigns', false);

        return match ($channel) {
            'sms' => $query
                ->where('contactable_sms', true)
                ->whereNotNull('phone')
                ->where('phone', '<>', ''),
            'email' => $query
                ->where('contactable_email', true)
                ->whereNotNull('email')
                ->where('email', '<>', ''),
            'all' => $query->where(function (Builder $builder): void {
                $builder
                    ->where(function (Builder $nested): void {
                        $nested->where('contactable_sms', true)->whereNotNull('phone')->where('phone', '<>', '');
                    })
                    ->orWhere(function (Builder $nested): void {
                        $nested->where('contactable_email', true)->whereNotNull('email')->where('email', '<>', '');
                    });
            }),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function availableChannelMap(Patient $patient): array
    {
        $phone = trim((string) $patient->phone);
        $email = trim((string) $patient->email);

        return [
            'sms' => $patient->contactable_sms && $phone !== '',
            'email' => $patient->contactable_email && $email !== '',
        ];
    }

    public function specializationsFromRaw(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(explode('||', $value))
            ->map(fn (string $entry) => trim($entry))
            ->filter()
            ->unique(fn (string $entry) => mb_strtolower($entry))
            ->sort()
            ->values()
            ->all();
    }

    private function applyAgeRule(Builder $query, string $operator, mixed $value): void
    {
        $age = is_numeric($value) ? (int) $value : null;
        if ($age === null || $age < 0) {
            return;
        }

        $cutoffDate = now()->subYears($age)->toDateString();
        $query->whereNotNull('birth_date');
        match ($operator) {
            'gte' => $query->whereDate('birth_date', '<=', $cutoffDate),
            'lte' => $query->whereDate('birth_date', '>=', $cutoffDate),
            default => $query->whereDate('birth_date', '=', $cutoffDate),
        };
    }

    private function applyBirthDateRule(Builder $query, string $operator, mixed $value): void
    {
        $date = $this->safeDate($value);
        if (! $date) {
            return;
        }

        $query->whereNotNull('birth_date');
        match ($operator) {
            'gte' => $query->whereDate('birth_date', '>=', $date->toDateString()),
            'lte' => $query->whereDate('birth_date', '<=', $date->toDateString()),
            default => $query->whereDate('birth_date', '=', $date->toDateString()),
        };
    }

    private function applyYearOfBirthRule(Builder $query, string $operator, mixed $value): void
    {
        $year = is_numeric($value) ? (int) $value : null;
        if ($year === null || $year < 1900) {
            return;
        }

        $query->whereNotNull('birth_date');
        match ($operator) {
            'gte' => $query->whereYear('birth_date', '>=', $year),
            'lte' => $query->whereYear('birth_date', '<=', $year),
            default => $query->whereYear('birth_date', '=', $year),
        };
    }

    private function applyPresenceRule(Builder $query, string $column, string $operator): void
    {
        $positive = $operator !== 'is_false';

        $query->where(function (Builder $builder) use ($column, $positive): void {
            if ($positive) {
                $builder->whereNotNull($column)->where($column, '<>', '');

                return;
            }

            $builder->whereNull($column)->orWhere($column, '');
        });
    }

    private function applySexRule(Builder $query, string $operator, mixed $value): void
    {
        $normalized = strtolower(trim((string) $value));

        if ($normalized === '') {
            return;
        }

        if ($normalized === '__null__') {
            if ($operator === 'neq') {
                $query->whereNotNull('sex');

                return;
            }

            $query->whereNull('sex');

            return;
        }

        if (! in_array($normalized, ['male', 'female', 'other'], true)) {
            return;
        }

        if ($operator === 'neq') {
            $query->where(function (Builder $builder) use ($normalized): void {
                $builder->whereNull('sex')->orWhere('sex', '<>', $normalized);
            });

            return;
        }

        $query->where('sex', $normalized);
    }

    private function applyBooleanRule(Builder $query, string $column, string $operator): void
    {
        $query->where($column, $operator !== 'is_false');
    }

    private function applyLastVisitRule(Builder $query, string $operator, mixed $value): void
    {
        $date = $this->safeDate($value);
        if (! $date) {
            return;
        }

        if ($operator === 'after') {
            $query->whereHas('performanceRecords', fn (Builder $builder) => $builder->whereDate('performed_at', '>', $date->toDateString()));

            return;
        }

        $query
            ->whereHas('performanceRecords')
            ->whereDoesntHave('performanceRecords', fn (Builder $builder) => $builder->whereDate('performed_at', '>=', $date->toDateString()));
    }

    private function applyInactiveMonthsRule(Builder $query, string $operator, mixed $value): void
    {
        $months = is_numeric($value) ? (int) $value : null;
        if ($months === null || $months < 0) {
            return;
        }

        $cutoff = now()->subMonthsNoOverflow($months)->startOfDay();

        if ($operator === 'lte') {
            $query->whereHas('performanceRecords', fn (Builder $builder) => $builder->whereDate('performed_at', '>=', $cutoff->toDateString()));

            return;
        }

        $query
            ->whereHas('performanceRecords')
            ->whereDoesntHave('performanceRecords', fn (Builder $builder) => $builder->whereDate('performed_at', '>', $cutoff->toDateString()));
    }

    private function applySpecializationRule(Builder $query, string $operator, mixed $value): void
    {
        $areaName = trim((string) $value);
        if ($areaName === '') {
            return;
        }

        $method = $operator === 'has_not' ? 'whereDoesntHave' : 'whereHas';
        $query->{$method}('performanceRecords', fn (Builder $builder) => $builder->where('category_name_snapshot', $areaName));
    }

    private function applyServiceRule(Builder $query, string $operator, mixed $value, string $displayValue): void
    {
        $serviceId = is_numeric($value) ? (int) $value : null;
        $serviceName = trim($displayValue);

        if ($serviceId === null && $serviceName === '') {
            return;
        }

        $callback = function (Builder $builder) use ($serviceId, $serviceName): void {
            $builder->where(function (Builder $nested) use ($serviceId, $serviceName): void {
                if ($serviceId !== null) {
                    $nested->orWhere('service_id', $serviceId);
                }

                if ($serviceName !== '') {
                    $nested->orWhere('service_name_snapshot', $serviceName);
                }
            });
        };

        if ($operator === 'never') {
            $query->whereDoesntHave('performanceRecords', $callback);

            return;
        }

        $query->whereHas('performanceRecords', $callback);
    }

    private function applyVisitsCountRule(Builder $query, string $operator, mixed $value): void
    {
        $count = is_numeric($value) ? (int) $value : null;
        if ($count === null || $count < 0) {
            return;
        }

        match ($operator) {
            'lte' => $query->has('performanceRecords', '<=', $count),
            'eq' => $query->has('performanceRecords', '=', $count),
            default => $query->has('performanceRecords', '>=', $count),
        };
    }

    private function applyResidenceCityRule(Builder $query, string $operator, mixed $value): void
    {
        $city = trim((string) $value);
        if ($city === '') {
            return;
        }

        match ($operator) {
            'neq' => $query
                ->whereNotNull('residence_city')
                ->whereRaw('LOWER(residence_city) <> ?', [mb_strtolower($city)]),
            'contains' => $query
                ->whereNotNull('residence_city')
                ->whereRaw('LOWER(residence_city) LIKE ?', ['%'.mb_strtolower($city).'%']),
            default => $query
                ->whereNotNull('residence_city')
                ->whereRaw('LOWER(residence_city) = ?', [mb_strtolower($city)]),
        };
    }

    private function applyDistanceFromRemedicRule(Builder $query, string $operator, mixed $value): void
    {
        $maxKm = is_numeric($value) ? (float) $value : null;
        if ($maxKm === null || $maxKm <= 0) {
            return;
        }

        $coords = $this->patientGeocodingService->remedicCoordinates();
        if ($coords['lat'] === null || $coords['lng'] === null) {
            return;
        }

        $distanceSql = '(6371 * ACOS(COS(RADIANS(?)) * COS(RADIANS(residence_latitude)) * COS(RADIANS(residence_longitude) - RADIANS(?)) + SIN(RADIANS(?)) * SIN(RADIANS(residence_latitude))))';

        $query
            ->whereNotNull('residence_latitude')
            ->whereNotNull('residence_longitude')
            ->whereRaw($distanceSql.' <= ?', [
                $coords['lat'],
                $coords['lng'],
                $coords['lat'],
                $maxKm,
            ]);
    }

    private function safeDate(mixed $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
