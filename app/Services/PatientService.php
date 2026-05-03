<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Services\Marketing\ItalianTaxCodeService;
use App\Services\Marketing\PatientGeocodingService;
use App\Services\Marketing\PatientSegmentQueryService;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientService
{
    public function __construct(
        private readonly PatientSegmentQueryService $segmentQueryService,
        private readonly ItalianTaxCodeService $taxCodeService,
        private readonly PatientGeocodingService $patientGeocodingService,
    ) {
    }

    public function baseQuery(array $filters = []): Builder
    {
        $query = $this->segmentQueryService->decorateWithMarketingMetrics(Patient::query());

        $query
            ->when($filters['q'] ?? null, function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $nested
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('tax_code', 'like', "%{$search}%")
                        ->orWhere('residence_city', 'like', "%{$search}%");
                });
            })
            ->when(array_key_exists('excluded_from_campaigns', $filters) && $filters['excluded_from_campaigns'] !== null, fn (Builder $builder) => $builder->where('excluded_from_campaigns', (bool) $filters['excluded_from_campaigns']))
            ->when(array_key_exists('contactable_sms', $filters) && $filters['contactable_sms'] !== null, fn (Builder $builder) => $builder->where('contactable_sms', (bool) $filters['contactable_sms']))
            ->when(array_key_exists('contactable_whatsapp', $filters) && $filters['contactable_whatsapp'] !== null, fn (Builder $builder) => $builder->where('contactable_whatsapp', (bool) $filters['contactable_whatsapp']))
            ->when(array_key_exists('contactable_email', $filters) && $filters['contactable_email'] !== null, fn (Builder $builder) => $builder->where('contactable_email', (bool) $filters['contactable_email']))
            ->when($filters['area_name'] ?? null, fn (Builder $builder, string $areaName) => $builder->whereHas('performanceRecords', fn (Builder $nested) => $nested->where('category_name_snapshot', $areaName)))
            ->when($filters['only_without_history'] ?? null, fn (Builder $builder) => $builder->doesntHave('performanceRecords'));

        $sort = (string) ($filters['sort'] ?? '-last_visit_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        return match ($field) {
            'full_name', 'birth_date', 'year_of_birth', 'created_at', 'updated_at' => $query->orderBy($field, $direction)->orderBy('id'),
            'last_visit_at' => $query->orderBy('last_visit_at', $direction)->orderBy('full_name'),
            default => $query->orderBy('full_name')->orderBy('id'),
        };
    }

    public function create(array $payload, User $actor): Patient
    {
        return DB::transaction(function () use ($payload, $actor): Patient {
            $patient = Patient::query()->create($this->stateForPersistence($payload, $actor));

            return $this->detail($patient);
        });
    }

    public function update(Patient $patient, array $payload, User $actor): Patient
    {
        return DB::transaction(function () use ($patient, $payload, $actor): Patient {
            $patient->fill($this->stateForPersistence($payload, $actor, $patient));
            $patient->save();

            return $this->detail($patient->refresh());
        });
    }

    public function delete(Patient $patient): void
    {
        $patient->delete();
    }

    public function detail(Patient $patient): Patient
    {
        $patient = $this->segmentQueryService
            ->decorateWithMarketingMetrics(Patient::query())
            ->whereKey($patient->id)
            ->firstOrFail();

        $this->segmentQueryService->hydrateVisitedSpecializations([$patient]);

        $recentPerformances = $patient->performanceRecords()
            ->with(['patients', 'service.category', 'professional'])
            ->orderByDesc('performed_at')
            ->orderByDesc('performance_records.id')
            ->limit(12)
            ->get();

        $specializationSummary = DB::table('patient_performance_record')
            ->join('performance_records', 'performance_records.id', '=', 'patient_performance_record.performance_record_id')
            ->selectRaw('performance_records.category_name_snapshot as area_name, COUNT(*) as visits_count, MAX(performance_records.performed_at) as last_visit_at')
            ->where('patient_performance_record.patient_id', $patient->id)
            ->whereNotNull('performance_records.category_name_snapshot')
            ->where('performance_records.category_name_snapshot', '<>', '')
            ->groupBy('performance_records.category_name_snapshot')
            ->orderByDesc('visits_count')
            ->orderBy('area_name')
            ->get()
            ->map(fn ($row) => [
                'area_name' => (string) $row->area_name,
                'visits_count' => (int) $row->visits_count,
                'last_visit_at' => optional($row->last_visit_at)->toDateString(),
            ])
            ->values()
            ->all();

        $patient->setRelation('performanceRecords', $recentPerformances);
        $patient->setAttribute('specialization_summary', $specializationSummary);
        $patient->setAttribute('available_channels', $this->segmentQueryService->availableChannelMap($patient));

        return $patient;
    }

    public function hydrateCollection(Collection $patients): Collection
    {
        $this->segmentQueryService->hydrateVisitedSpecializations($patients);

        return $patients->each(fn (Patient $patient) => $patient->setAttribute(
            'available_channels',
            $this->segmentQueryService->availableChannelMap($patient),
        ));
    }

    private function stateForPersistence(array $payload, User $actor, ?Patient $existing = null): array
    {
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $taxCode = $this->taxCodeService->normalize($payload['tax_code'] ?? null);
        $derivedBirthDate = $this->taxCodeService->extractBirthDate($taxCode);
        $birthDate = $this->normalizeBirthDate(
            $payload['birth_date'] ?? null,
            $payload['year_of_birth'] ?? null,
            $derivedBirthDate,
        );
        $residenceAddress = $this->nullableTrimmedString($payload['residence_address'] ?? null);
        $residenceCity = $this->nullableTrimmedString($payload['residence_city'] ?? null);
        $residenceZip = $this->nullableTrimmedString($payload['residence_zip'] ?? null);
        $geocoding = $this->patientGeocodingService->geocode($residenceAddress, $residenceCity, $residenceZip);
        $geocodedAt = $geocoding['status'] === 'ok' ? now() : null;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => trim($lastName.' '.$firstName),
            'tax_code' => $taxCode,
            'sex' => $this->normalizeSex($payload['sex'] ?? null),
            'birth_date' => $birthDate,
            'year_of_birth' => $this->resolveYearOfBirth($birthDate, $payload['year_of_birth'] ?? null),
            'phone' => $this->nullableTrimmedString($payload['phone'] ?? null),
            'email' => $this->nullableTrimmedString($payload['email'] ?? null),
            'residence_address' => $residenceAddress,
            'residence_city' => $residenceCity,
            'residence_zip' => $residenceZip,
            'residence_latitude' => $geocoding['lat'],
            'residence_longitude' => $geocoding['lng'],
            'geocoding_status' => $geocoding['status'],
            'geocoded_at' => $geocodedAt,
            'contactable_sms' => (bool) ($payload['contactable_sms'] ?? false),
            'contactable_whatsapp' => (bool) ($payload['contactable_whatsapp'] ?? false),
            'contactable_email' => (bool) ($payload['contactable_email'] ?? false),
            'excluded_from_campaigns' => (bool) ($payload['excluded_from_campaigns'] ?? false),
            'notes' => $this->nullableTrimmedString($payload['notes'] ?? null),
            'created_by' => $existing?->created_by ?? $actor->id,
            'updated_by' => $actor->id,
        ];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeSex(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['male', 'female', 'other'], true)
            ? $normalized
            : null;
    }

    private function normalizeBirthDate(mixed $birthDate, mixed $yearOfBirth, ?string $derivedBirthDate = null): ?string
    {
        try {
            if ($birthDate) {
                return Carbon::parse((string) $birthDate)->toDateString();
            }

            if ($derivedBirthDate) {
                return $derivedBirthDate;
            }

            $year = is_numeric($yearOfBirth) ? (int) $yearOfBirth : null;
            if ($year && $year >= 1900 && $year <= (int) now()->year) {
                return sprintf('%04d-01-01', $year);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function resolveYearOfBirth(mixed $birthDate, mixed $yearOfBirth): ?int
    {
        if ($birthDate) {
            try {
                return (int) Carbon::parse((string) $birthDate)->year;
            } catch (\Throwable) {
                return null;
            }
        }

        return is_numeric($yearOfBirth) ? (int) $yearOfBirth : null;
    }
}
