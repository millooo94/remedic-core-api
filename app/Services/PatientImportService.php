<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Services\Marketing\ItalianTaxCodeService;
use App\Services\Marketing\PatientGeocodingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PatientImportService
{
    public function __construct(
        private readonly ItalianTaxCodeService $taxCodeService,
        private readonly PatientGeocodingService $patientGeocodingService,
    ) {}

    public function import(UploadedFile $file, User $actor, bool $updateExisting = true): array
    {
        $rows = $this->loadRowsFromFile($file);
        if ($rows->isEmpty()) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];
        }

        $headers = collect($rows->shift() ?? [])
            ->map(fn ($value) => $this->normalizeHeader((string) $value))
            ->values();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($rows, $headers, $actor, $updateExisting, &$created, &$updated, &$skipped, &$errors): void {
            foreach ($rows->values() as $index => $row) {
                $mapped = $this->mapRow($headers, collect($row));

                if ($this->rowIsEmpty($mapped)) {
                    $skipped++;

                    continue;
                }

                $validator = Validator::make($mapped, [
                    'first_name' => ['required', 'string', 'max:120'],
                    'last_name' => ['required', 'string', 'max:120'],
                    'birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
                    'year_of_birth' => ['nullable', 'integer', 'between:1900,'.now()->year],
                    'tax_code' => ['nullable', 'string', 'size:16'],
                    'phone' => ['nullable', 'string', 'max:40'],
                    'email' => ['nullable', 'email', 'max:190'],
                    'residence_address' => ['nullable', 'string', 'max:190'],
                    'residence_city' => ['nullable', 'string', 'max:120'],
                    'residence_province' => ['nullable', 'string', 'max:120'],
                    'residence_zip' => ['nullable', 'string', 'max:10', 'regex:/^\d{5}$/'],
                    'contactable_sms' => ['nullable', 'boolean'],
                    'contactable_whatsapp' => ['nullable', 'boolean'],
                    'contactable_email' => ['nullable', 'boolean'],
                    'excluded_from_campaigns' => ['nullable', 'boolean'],
                    'notes' => ['nullable', 'string'],
                ]);

                if ($validator->fails()) {
                    $skipped++;
                    $messages = collect($validator->errors()->toArray())
                        ->flatMap(fn (array $fieldMessages, string $field) => collect($fieldMessages)->map(fn (string $message) => "{$field}: {$message}"))
                        ->implode(' ');
                    $errors[] = [
                        'row' => $index + 2,
                        'message' => $messages !== '' ? $messages : collect($validator->errors()->all())->implode(' '),
                    ];

                    continue;
                }

                $payload = $validator->validated();
                $payload['first_name'] = trim((string) $payload['first_name']);
                $payload['last_name'] = trim((string) $payload['last_name']);
                $payload['full_name'] = trim($payload['last_name'].' '.$payload['first_name']);
                $payload['tax_code'] = $this->taxCodeService->normalize($payload['tax_code'] ?? null);
                $derivedBirthDate = $this->taxCodeService->extractBirthDate($payload['tax_code']);
                $payload['birth_date'] = $this->normalizeBirthDate($payload['birth_date'] ?? null, $payload['year_of_birth'] ?? null, $derivedBirthDate);
                $payload['year_of_birth'] = $this->resolveYearOfBirth($payload['birth_date'] ?? null, $payload['year_of_birth'] ?? null);
                $payload['phone'] = $this->nullableTrimmedString($payload['phone'] ?? null);
                $payload['email'] = $this->nullableTrimmedString($payload['email'] ?? null);
                $payload['residence_address'] = $this->nullableTrimmedString($payload['residence_address'] ?? null);
                $payload['residence_city'] = $this->nullableTrimmedString($payload['residence_city'] ?? null);
                $payload['residence_province'] = $this->nullableTrimmedString($payload['residence_province'] ?? null);
                $payload['residence_zip'] = $this->nullableTrimmedString($payload['residence_zip'] ?? null);
                $geocoding = $this->patientGeocodingService->geocode($payload['residence_address'], $payload['residence_city'], $payload['residence_zip']);
                $payload['residence_latitude'] = $geocoding['lat'];
                $payload['residence_longitude'] = $geocoding['lng'];
                $payload['geocoding_status'] = $geocoding['status'];
                $payload['geocoded_at'] = $geocoding['status'] === 'ok' ? now() : null;
                $payload['notes'] = $this->nullableTrimmedString($payload['notes'] ?? null);
                $contactableSms = $payload['contactable_sms'] ?? null;
                $contactableWhatsapp = $payload['contactable_whatsapp'] ?? null;
                $contactableEmail = $payload['contactable_email'] ?? null;
                $payload['contactable_sms'] = (bool) ($contactableSms ?? true);
                $payload['contactable_whatsapp'] = (bool) ($contactableWhatsapp ?? true);
                $payload['contactable_email'] = (bool) ($contactableEmail ?? false);
                $payload['excluded_from_campaigns'] = (bool) ($payload['excluded_from_campaigns'] ?? false);

                $existing = $this->findExistingPatient($payload);

                if ($existing && ! $updateExisting) {
                    $skipped++;

                    continue;
                }

                if ($existing) {
                    $payload['contactable_sms'] = $contactableSms === null ? $existing->contactable_sms : $payload['contactable_sms'];
                    $payload['contactable_whatsapp'] = $contactableWhatsapp === null ? $existing->contactable_whatsapp : $payload['contactable_whatsapp'];
                    $payload['contactable_email'] = $contactableEmail === null ? $existing->contactable_email : $payload['contactable_email'];
                    $existing->fill(array_merge($payload, [
                        'updated_by' => $actor->id,
                    ]));
                    $existing->save();
                    $updated++;

                    continue;
                }

                Patient::query()->create(array_merge($payload, [
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]));
                $created++;
            }
        });

        return compact('created', 'updated', 'skipped', 'errors');
    }

    private function loadRowsFromFile(UploadedFile $file): Collection
    {
        return $this->loadRowsFromXml($file);
    }

    private function loadRowsFromXml(UploadedFile $file): Collection
    {
        $content = $file->getContent();
        if ($content === false || trim($content) === '') {
            return collect();
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (! $xml) {
            return collect();
        }

        $fieldOrder = [
            'nome',
            'cognome',
            'codice_fiscale',
            'data_nascita',
            'telefono',
            'ultima_visita',
            'email',
            'citta_residenza',
            'indirizzo_residenza',
            'cap',
            'contattabile_sms',
            'contattabile_email',
            'escluso_campagne',
            'note',
        ];

        $rows = [$fieldOrder];
        foreach ($xml->children() as $patientNode) {
            $row = [];
            foreach ($fieldOrder as $field) {
                $value = null;
                if (isset($patientNode->{$field})) {
                    $value = (string) $patientNode->{$field};
                } elseif (isset($patientNode->{strtoupper($field)})) {
                    $value = (string) $patientNode->{strtoupper($field)};
                }
                $row[] = $value;
            }
            $rows[] = $row;
        }

        return collect($rows);
    }

    private function mapRow(Collection $headers, Collection $row): array
    {
        $mapped = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $mapped[$header] = $row->get($index);
        }

        $firstName = $this->normalizeScalar($mapped['first_name'] ?? $mapped['nome'] ?? null);
        $lastName = $this->normalizeScalar($mapped['last_name'] ?? $mapped['cognome'] ?? null);
        $birthDate = $this->normalizeScalar($mapped['birth_date'] ?? $mapped['data_nascita'] ?? null);
        $yearOfBirth = $this->normalizeScalar($mapped['year_of_birth'] ?? $mapped['anno_nascita'] ?? null);
        $taxCode = $this->normalizeScalar($mapped['tax_code'] ?? $mapped['codice_fiscale'] ?? null);
        $phone = $this->normalizeScalar($mapped['phone'] ?? $mapped['telefono'] ?? null);
        $email = $this->normalizeScalar($mapped['email'] ?? null);
        $residenceAddress = $this->normalizeScalar($mapped['residence_address'] ?? $mapped['indirizzo_residenza'] ?? $mapped['indirizzo'] ?? null);
        $residenceCity = $this->normalizeScalar($mapped['residence_city'] ?? $mapped['citta_residenza'] ?? $mapped['citta'] ?? null);
        $residenceProvince = $this->normalizeScalar($mapped['residence_province'] ?? $mapped['provincia_residenza'] ?? $mapped['provincia'] ?? null);
        $residenceZip = $this->normalizeScalar($mapped['residence_zip'] ?? $mapped['cap'] ?? null);
        $lastVisit = $this->normalizeScalar($mapped['ultima_visita'] ?? null);
        $notes = $this->normalizeScalar($mapped['notes'] ?? $mapped['note'] ?? null);

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'year_of_birth' => $yearOfBirth,
            'tax_code' => $taxCode,
            'phone' => $phone,
            'email' => $email,
            'residence_address' => $residenceAddress,
            'residence_city' => $residenceCity,
            'residence_province' => $residenceProvince,
            'residence_zip' => $residenceZip,
            'contactable_sms' => $this->normalizeBoolean($mapped['contactable_sms'] ?? $mapped['contattabile_sms'] ?? null),
            'contactable_whatsapp' => $this->normalizeBoolean($mapped['contactable_whatsapp'] ?? $mapped['contattabile_whatsapp'] ?? null),
            'contactable_email' => $this->normalizeBoolean($mapped['contactable_email'] ?? $mapped['contattabile_email'] ?? null),
            'excluded_from_campaigns' => $this->normalizeBoolean($mapped['excluded_from_campaigns'] ?? $mapped['escluso_campagne'] ?? null),
            'notes' => $notes,
            'ultima_visita' => $lastVisit,
        ];
    }

    private function rowIsEmpty(array $mapped): bool
    {
        return collect($mapped)->filter(fn ($value) => $value !== null && trim((string) $value) !== '')->isEmpty();
    }

    private function findExistingPatient(array $payload): ?Patient
    {
        $email = $payload['email'] ?? null;
        if ($email) {
            $existing = Patient::query()->where('email', $email)->first();
            if ($existing) {
                return $existing;
            }
        }

        $phone = $payload['phone'] ?? null;
        if ($phone) {
            $existing = Patient::query()->where('phone', $phone)->first();
            if ($existing) {
                return $existing;
            }
        }

        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $yearOfBirth = $payload['year_of_birth'] ?? null;
        $birthDate = $payload['birth_date'] ?? null;

        if ($firstName !== '' && $lastName !== '') {
            $query = Patient::query()
                ->where('first_name', $firstName)
                ->where('last_name', $lastName);

            if ($birthDate) {
                return $query->whereDate('birth_date', $birthDate)->first();
            }

            if ($yearOfBirth) {
                return $query->where('year_of_birth', $yearOfBirth)->first();
            }
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim(str_replace([' ', '-'], '_', $value)));
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = collect($value)
                ->map(fn (mixed $entry) => trim((string) $entry))
                ->filter(fn (string $entry) => $entry !== '')
                ->implode(' ');
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'si', 'sì', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeBirthDate(mixed $birthDate, mixed $yearOfBirth, ?string $derivedBirthDate = null): ?string
    {
        if ($birthDate) {
            try {
                return Carbon::parse((string) $birthDate)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if ($derivedBirthDate) {
            return $derivedBirthDate;
        }

        $year = is_numeric($yearOfBirth) ? (int) $yearOfBirth : null;
        if ($year && $year >= 1900 && $year <= (int) now()->year) {
            return sprintf('%04d-01-01', $year);
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
