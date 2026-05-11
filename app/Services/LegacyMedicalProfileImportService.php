<?php

namespace App\Services;

use App\Enums\ProfessionalSubjectType;
use App\Models\Professional;
use App\Models\ProfessionalAcademicSpecialization;
use App\Models\ProfessionalBoardRegistration;
use App\Models\ProfessionalDegree;
use App\Models\ProfessionalPublicProfile;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\Specialization;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class LegacyMedicalProfileImportService
{
    public const DEFAULT_GROUPS = [
        'specializations',
        'professional_public_profiles',
        'service_specializations',
        'professional_specializations',
        'professional_services',
        'professional_degrees',
        'professional_academic_specializations',
        'professional_board_registrations',
    ];

    public function import(array $groups = self::DEFAULT_GROUPS, bool $dryRun = true): array
    {
        $connection = DB::connection('legacy_backend');
        $normalizedGroups = $this->normalizeGroups($groups);

        $report = [
            'dry_run' => $dryRun,
            'groups' => $normalizedGroups,
            'items' => [],
        ];

        foreach ($normalizedGroups as $group) {
            $report['items'][$group] = $this->importGroup($connection, $group, $dryRun);
        }

        return $report;
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    protected function normalizeGroups(array $groups): array
    {
        $groups = $groups === [] ? self::DEFAULT_GROUPS : $groups;
        $allowed = self::DEFAULT_GROUPS;

        return array_values(array_intersect($allowed, array_unique(array_map(
            static fn (string $group): string => trim($group),
            $groups,
        ))));
    }

    protected function importGroup(ConnectionInterface $connection, string $group, bool $dryRun): array
    {
        return match ($group) {
            'specializations' => $this->importSpecializations($connection, $dryRun),
            'professional_public_profiles' => $this->importProfessionalPublicProfiles($connection, $dryRun),
            'service_specializations' => $this->importServiceSpecializations($connection, $dryRun),
            'professional_specializations' => $this->importProfessionalSpecializations($connection, $dryRun),
            'professional_services' => $this->importProfessionalServices($connection, $dryRun),
            'professional_degrees' => $this->importProfessionalDegrees($connection, $dryRun),
            'professional_academic_specializations' => $this->importProfessionalAcademicSpecializations($connection, $dryRun),
            'professional_board_registrations' => $this->importProfessionalBoardRegistrations($connection, $dryRun),
            default => $this->reportRowCounts([], 0, 0, 0, ["Gruppo non supportato: {$group}"]),
        };
    }

    protected function importSpecializations(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'specializations');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = Arr::only($row, [
                'name',
                'slug',
                'short_description',
                'intro_text',
                'local_intro_text',
                'local_area_notes',
                'seo_title',
                'local_seo_title',
                'seo_description',
                'local_seo_description',
                'seo_h1',
                'local_seo_h1',
                'is_local_seo_enabled',
                'canonical_url',
                'robots',
                'og_title',
                'og_description',
                'is_active',
                'sort_order',
                'created_at',
                'updated_at',
            ]);

            $target = Specialization::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                Specialization::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated);
    }

    protected function importProfessionalPublicProfiles(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'doctors');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $resolution = $this->resolveProfessionalForDoctor($row);

            if (! $resolution['matched']) {
                $skipped++;
                $warnings[] = $resolution['warning'];

                continue;
            }

            $professional = $resolution['professional'];
            $existingProfile = ProfessionalPublicProfile::query()
                ->where('legacy_backend_id', $row['id'])
                ->first()
                ?? ProfessionalPublicProfile::query()
                    ->where('professional_id', $professional->getKey())
                    ->first();

            $slugConflict = ProfessionalPublicProfile::query()
                ->where('slug', $row['slug'])
                ->when($existingProfile !== null, fn ($query) => $query->whereKeyNot($existingProfile->getKey()))
                ->first();

            if ($slugConflict !== null) {
                $skipped++;
                $warnings[] = "Doctor legacy #{$row['id']} skipped: slug [{$row['slug']}] già usato da un altro profilo pubblico.";

                continue;
            }

            if ($existingProfile === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                $profile = $existingProfile ?? new ProfessionalPublicProfile();
                $profile->fill([
                    'professional_id' => $professional->getKey(),
                    'slug' => $row['slug'],
                    'title_prefix' => $row['title_prefix'],
                    'short_bio' => $row['short_bio'],
                    'registration_number' => $row['registration_number'],
                    'birth_date' => $row['birth_date'],
                    'birth_place' => $row['birth_place'],
                    'profile_image_path' => $row['profile_image'],
                    'seo_title' => $row['seo_title'],
                    'seo_description' => $row['seo_description'],
                    'seo_h1' => $row['seo_h1'],
                    'canonical_url' => $row['canonical_url'],
                    'robots' => $row['robots'],
                    'og_title' => $row['og_title'],
                    'og_description' => $row['og_description'],
                    'is_active' => $row['is_active'],
                    'sort_order' => $row['sort_order'],
                ]);
                $profile->legacy_backend_id = $row['id'];
                $profile->save();
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    protected function importServiceSpecializations(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'service_specialization');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $service = Service::query()->where('legacy_backend_id', $row['service_id'])->first();
            $specialization = Specialization::query()->where('legacy_backend_id', $row['specialization_id'])->first();

            if ($service === null || $specialization === null) {
                $skipped++;
                $warnings[] = "ServiceSpecialization legacy #{$row['id']} skipped: service o specialization non mappati.";

                continue;
            }

            $existing = DB::table('service_specialization')
                ->where('service_id', $service->getKey())
                ->where('specialization_id', $specialization->getKey())
                ->exists();

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }

            if (! $dryRun) {
                DB::table('service_specialization')->updateOrInsert(
                    [
                        'service_id' => $service->getKey(),
                        'specialization_id' => $specialization->getKey(),
                    ],
                    [
                        'is_primary' => $row['is_primary'],
                        'sort_order' => $row['sort_order'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                    ],
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    protected function importProfessionalSpecializations(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'doctor_specialization');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $profile = ProfessionalPublicProfile::query()->where('legacy_backend_id', $row['doctor_id'])->first();
            $specialization = Specialization::query()->where('legacy_backend_id', $row['specialization_id'])->first();

            if ($profile === null || $specialization === null) {
                $skipped++;
                $warnings[] = "DoctorSpecialization legacy #{$row['id']} skipped: profilo pubblico o specialization non mappati.";

                continue;
            }

            $existing = DB::table('professional_specialization')
                ->where('professional_id', $profile->professional_id)
                ->where('specialization_id', $specialization->getKey())
                ->exists();

            if ($existing) {
                $updated++;
            } else {
                $created++;
            }

            if (! $dryRun) {
                DB::table('professional_specialization')->updateOrInsert(
                    [
                        'professional_id' => $profile->professional_id,
                        'specialization_id' => $specialization->getKey(),
                    ],
                    [
                        'is_primary' => $row['is_primary'],
                        'sort_order' => $row['sort_order'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                    ],
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    protected function importProfessionalServices(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'doctor_service');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $profile = ProfessionalPublicProfile::query()->where('legacy_backend_id', $row['doctor_id'])->first();
            $service = Service::query()->where('legacy_backend_id', $row['service_id'])->first();

            if ($profile === null || $service === null) {
                $skipped++;
                $warnings[] = "DoctorService legacy #{$row['id']} skipped: profilo pubblico o service non mappati.";

                continue;
            }

            $existing = ProfessionalService::query()
                ->where('legacy_backend_id', $row['id'])
                ->first()
                ?? ProfessionalService::query()
                    ->where('professional_id', $profile->professional_id)
                    ->where('service_id', $service->getKey())
                    ->first();

            if ($existing === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                $professionalService = $existing ?? new ProfessionalService();
                $professionalService->fill([
                    'professional_id' => $profile->professional_id,
                    'service_id' => $service->getKey(),
                    'is_featured' => $row['is_featured'],
                    'editorial_notes' => $row['notes'],
                ]);
                $professionalService->legacy_backend_id = $row['id'];
                $professionalService->save();
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    protected function importProfessionalDegrees(ConnectionInterface $connection, bool $dryRun): array
    {
        return $this->importDoctorChildTable(
            connection: $connection,
            table: 'doctor_degrees',
            targetModelClass: ProfessionalDegree::class,
            payloadCallback: fn (array $row, ProfessionalPublicProfile $profile): array => [
                'professional_id' => $profile->professional_id,
                'title' => $row['title'],
                'awarded_on' => $row['awarded_on'],
                'sort_order' => $row['sort_order'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            dryRun: $dryRun,
        );
    }

    protected function importProfessionalAcademicSpecializations(ConnectionInterface $connection, bool $dryRun): array
    {
        return $this->importDoctorChildTable(
            connection: $connection,
            table: 'doctor_academic_specializations',
            targetModelClass: ProfessionalAcademicSpecialization::class,
            payloadCallback: fn (array $row, ProfessionalPublicProfile $profile): array => [
                'professional_id' => $profile->professional_id,
                'title' => $row['title'],
                'awarded_on' => $row['awarded_on'],
                'sort_order' => $row['sort_order'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            dryRun: $dryRun,
        );
    }

    protected function importProfessionalBoardRegistrations(ConnectionInterface $connection, bool $dryRun): array
    {
        return $this->importDoctorChildTable(
            connection: $connection,
            table: 'doctor_board_registrations',
            targetModelClass: ProfessionalBoardRegistration::class,
            payloadCallback: fn (array $row, ProfessionalPublicProfile $profile): array => [
                'professional_id' => $profile->professional_id,
                'board_name' => $row['board_name'],
                'registered_on' => $row['registered_on'],
                'sort_order' => $row['sort_order'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ],
            dryRun: $dryRun,
        );
    }

    /**
     * @param  class-string<Model>  $targetModelClass
     */
    protected function importDoctorChildTable(
        ConnectionInterface $connection,
        string $table,
        string $targetModelClass,
        callable $payloadCallback,
        bool $dryRun = false,
    ): array {
        $rows = $this->fetchRows($connection, $table);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $profile = ProfessionalPublicProfile::query()->where('legacy_backend_id', $row['doctor_id'])->first();

            if ($profile === null) {
                $skipped++;
                $warnings[] = "{$table} legacy #{$row['id']} skipped: profilo pubblico non mappato per doctor #{$row['doctor_id']}.";

                continue;
            }

            /** @var Model|null $existing */
            $existing = $targetModelClass::query()->where('legacy_backend_id', $row['id'])->first();

            if ($existing === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                $model = $existing ?? new $targetModelClass();
                $model->fill($payloadCallback($row, $profile));
                $model->legacy_backend_id = $row['id'];
                $model->save();
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{matched:bool, professional:?Professional, warning:?string}
     */
    protected function resolveProfessionalForDoctor(array $row): array
    {
        $existingProfile = ProfessionalPublicProfile::query()
            ->where('legacy_backend_id', $row['id'])
            ->first();

        if ($existingProfile !== null && $existingProfile->professional !== null) {
            return [
                'matched' => true,
                'professional' => $existingProfile->professional,
                'warning' => null,
            ];
        }

        $firstName = $this->normalizeText((string) $row['first_name']);
        $lastName = $this->normalizeText((string) $row['last_name']);
        $fullName = $this->normalizeText((string) $row['full_name']);

        $candidates = Professional::query()
            ->where('subject_type', ProfessionalSubjectType::Individual->value)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [$firstName])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [$lastName])
            ->get();

        if ($candidates->count() === 1) {
            return [
                'matched' => true,
                'professional' => $candidates->first(),
                'warning' => null,
            ];
        }

        if ($candidates->count() > 1) {
            return [
                'matched' => false,
                'professional' => null,
                'warning' => "Doctor legacy #{$row['id']} non importato: match ambiguo su first_name/last_name [{$row['first_name']} {$row['last_name']}].",
            ];
        }

        $fullNameCandidates = Professional::query()
            ->where('subject_type', ProfessionalSubjectType::Individual->value)
            ->whereRaw('LOWER(TRIM(full_name)) = ?', [$fullName])
            ->get();

        if ($fullNameCandidates->count() === 1) {
            return [
                'matched' => true,
                'professional' => $fullNameCandidates->first(),
                'warning' => null,
            ];
        }

        if ($fullNameCandidates->count() > 1) {
            return [
                'matched' => false,
                'professional' => null,
                'warning' => "Doctor legacy #{$row['id']} non importato: match ambiguo su full_name [{$row['full_name']}].",
            ];
        }

        return [
            'matched' => false,
            'professional' => null,
            'warning' => "Doctor legacy #{$row['id']} non importato: nessun professional trovato per [{$row['full_name']}].",
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchRows(ConnectionInterface $connection, string $table): array
    {
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        return $connection->table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    protected function normalizeText(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $warnings
     * @return array{source_records:int,created:int,updated:int,skipped:int,warnings:list<string>}
     */
    protected function reportRowCounts(array $rows, int $created, int $updated, int $skipped = 0, array $warnings = []): array
    {
        return [
            'source_records' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
