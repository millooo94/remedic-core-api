<?php

namespace App\Services;

use App\Enums\ProfessionalSubjectType;
use App\Models\ApplicationSetting;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseRecordCompetence;
use App\Models\ExpenseTemplate;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignDelivery;
use App\Models\MarketingSegment;
use App\Models\MarketingSegmentManualRecipient;
use App\Models\OldCoreImportMapping;
use App\Models\Patient;
use App\Models\PerformanceRecord;
use App\Models\PerformanceRecordSplit;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Reminder;
use App\Models\Service;
use App\Models\ServiceAlias;
use App\Models\ServiceCategory;
use App\Models\Specialization;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class OldCoreDataImportService
{
    public const SOURCE_CONNECTION = 'old_core';

    public const DEFAULT_GROUPS = [
        'specializations',
        'professionals',
        'patients',
        'services',
        'performance-records',
        'expenses',
        'marketing',
        'settings',
    ];

    /**
     * @var array<string, list<string>>
     */
    protected const GROUP_SOURCE_TABLES = [
        'specializations' => ['service_categories'],
        'professionals' => ['professionals', 'professional_service_categories'],
        'patients' => ['patients'],
        'services' => ['services', 'service_aliases', 'professional_services'],
        'performance-records' => ['performance_records', 'patient_performance_record', 'performance_record_splits'],
        'expenses' => ['expense_categories', 'expense_templates', 'expense_records', 'expense_record_competences', 'cash_movements'],
        'marketing' => ['marketing_segments', 'marketing_segment_manual_recipients', 'marketing_campaigns', 'marketing_campaign_deliveries'],
        'settings' => ['application_settings', 'reminders'],
    ];

    /**
     * @var array<string, list<string>>
     */
    protected const GROUP_TARGET_TABLES = [
        'specializations' => ['service_categories', 'specializations'],
        'professionals' => ['professionals', 'professional_service_categories', 'professional_specialization'],
        'patients' => ['patients'],
        'services' => ['services', 'service_aliases', 'professional_services', 'service_specialization'],
        'performance-records' => ['performance_records', 'patient_performance_record', 'performance_record_splits'],
        'expenses' => ['expense_categories', 'expense_templates', 'expense_records', 'expense_record_competences', 'cash_movements'],
        'marketing' => ['marketing_segments', 'marketing_segment_manual_recipients', 'marketing_campaigns', 'marketing_campaign_deliveries'],
        'settings' => ['application_settings', 'reminders'],
    ];

    /**
     * @var list<string>
     */
    protected const NON_IMPORTABLE_SOURCE_TABLES = [
        'audit_logs',
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'personal_access_tokens',
        'sessions',
        'users',
    ];

    /**
     * @var list<string>
     */
    protected const REBUILD_CLEAR_ORDER = [
        'marketing_campaign_deliveries',
        'marketing_campaigns',
        'marketing_segment_manual_recipients',
        'marketing_segments',
        'cash_movements',
        'expense_record_competences',
        'expense_records',
        'expense_templates',
        'performance_record_splits',
        'patient_performance_record',
        'performance_records',
        'service_specialization',
        'professional_services',
        'professional_specialization',
        'professional_service_categories',
        'service_aliases',
        'services',
        'professional_board_registrations',
        'professional_academic_specializations',
        'professional_degrees',
        'professional_public_profiles',
        'expense_categories',
        'service_categories',
        'specializations',
        'patients',
        'professionals',
        'reminders',
        'application_settings',
        'old_core_import_mappings',
    ];

    protected bool $disableNaturalMatching = false;

    public function __construct(
        protected OldCoreDumpAnalyzer $dumpAnalyzer,
    ) {}

    /**
     * @param  list<string>  $groups
     * @return array{
     *     dry_run:bool,
     *     write:bool,
     *     groups:list<string>,
     *     source_connection_available:bool,
     *     analysis:array<string,mixed>,
     *     items:array<string,array<string,mixed>>,
     *     totals:array<string,int>
     * }
     */
    public function import(array $groups = self::DEFAULT_GROUPS, bool $dryRun = true): array
    {
        $normalizedGroups = $this->normalizeGroups($groups);
        $dumpTables = $this->dumpAnalyzer->analyze();
        $sourceConnection = $this->resolveSourceConnection();
        $sourceAvailable = $sourceConnection !== null;

        $report = [
            'dry_run' => $dryRun,
            'write' => ! $dryRun,
            'groups' => $normalizedGroups,
            'source_connection_available' => $sourceAvailable,
            'analysis' => $this->buildAnalysis($dumpTables, $normalizedGroups, $sourceAvailable),
            'items' => [],
            'totals' => [
                'source_records' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'warnings' => 0,
                'unmappable' => 0,
            ],
        ];

        foreach ($normalizedGroups as $group) {
            try {
                if (! $sourceAvailable) {
                    $item = $this->emptyItem($group);
                    $item['warnings'][] = sprintf(
                        'Connessione %s non configurata o non raggiungibile: dry-run limitato all analisi del dump SQL.',
                        self::SOURCE_CONNECTION,
                    );
                } elseif ($dryRun) {
                    $item = $this->importGroup($sourceConnection, $group, true);
                } else {
                    $item = DB::transaction(
                        fn (): array => $this->importGroup($sourceConnection, $group, false),
                        3,
                    );
                }
            } catch (Throwable $throwable) {
                $item = $this->emptyItem($group);
                $item['errors'][] = $throwable->getMessage();
            }

            $report['items'][$group] = $item;
            $report['totals']['source_records'] += (int) ($item['source_records'] ?? 0);
            $report['totals']['created'] += (int) ($item['created'] ?? 0);
            $report['totals']['updated'] += (int) ($item['updated'] ?? 0);
            $report['totals']['skipped'] += (int) ($item['skipped'] ?? 0);
            $report['totals']['errors'] += count($item['errors'] ?? []);
            $report['totals']['warnings'] += count($item['warnings'] ?? []);
            $report['totals']['unmappable'] += count($item['unmappable'] ?? []);
        }

        return $report;
    }

    public function sourceConnectionConfigured(): bool
    {
        $connection = config('database.connections.'.self::SOURCE_CONNECTION, []);

        return filled($connection['database'] ?? null);
    }

    public function sourceDumpPath(): string
    {
        return $this->dumpAnalyzer->defaultDumpPath();
    }

    /**
     * @return array{
     *     dry_run:bool,
     *     write:bool,
     *     preserve_duplicates:bool,
     *     source_connection_available:bool,
     *     analysis:array<string,mixed>,
     *     clear_plan:array<string,int>,
     *     items:array<string,array<string,mixed>>,
     *     totals:array<string,int>
     * }
     */
    public function rebuildFromSource(bool $dryRun = true): array
    {
        $groups = self::DEFAULT_GROUPS;
        $dumpTables = $this->dumpAnalyzer->analyze();
        $sourceConnection = $this->resolveSourceConnection();
        $sourceAvailable = $sourceConnection !== null;
        $clearTables = $this->existingRebuildTables();

        $report = [
            'dry_run' => $dryRun,
            'write' => ! $dryRun,
            'preserve_duplicates' => true,
            'source_connection_available' => $sourceAvailable,
            'analysis' => array_merge(
                $this->buildAnalysis($dumpTables, $groups, $sourceAvailable),
                [
                    'mode' => 'rebuild_from_source',
                    'clear_tables' => $clearTables,
                    'technical_tables_kept' => [
                        'migrations',
                        'cache',
                        'cache_locks',
                        'jobs',
                        'job_batches',
                        'failed_jobs',
                        'sessions',
                        'password_reset_tokens',
                        'personal_access_tokens',
                    ],
                    'assumptions' => array_merge(
                        $this->buildAnalysis($dumpTables, $groups, $sourceAvailable)['assumptions'],
                        [
                            'La modalita rebuild svuota i dati applicativi del nuovo DB senza toccare lo schema Laravel.',
                            'Ogni riga di old_core.patients viene importata come nuovo paziente, anche con codici fiscali o telefoni duplicati.',
                            'Le relazioni vengono ricostruite esclusivamente tramite old_core_import_mappings generata durante il rebuild.',
                        ],
                    ),
                ],
            ),
            'clear_plan' => $this->targetRowCounts($clearTables),
            'items' => [],
            'totals' => [
                'source_records' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'warnings' => 0,
                'unmappable' => 0,
                'rows_to_delete' => array_sum($this->targetRowCounts($clearTables)),
            ],
        ];

        if (! $sourceAvailable) {
            foreach ($groups as $group) {
                $item = $this->emptyItem($group);
                $item['warnings'][] = sprintf(
                    'Connessione %s non configurata o non raggiungibile: il rebuild richiede la sorgente old_core attiva.',
                    self::SOURCE_CONNECTION,
                );
                $report['items'][$group] = $item;
                $report['totals']['warnings'] += count($item['warnings']);
            }

            return $report;
        }

        if ($dryRun) {
            foreach ($groups as $group) {
                $item = $this->simulateRebuildGroup($sourceConnection, $group);
                $report['items'][$group] = $item;
                $report['totals']['source_records'] += (int) ($item['source_records'] ?? 0);
                $report['totals']['created'] += (int) ($item['created'] ?? 0);
                $report['totals']['updated'] += (int) ($item['updated'] ?? 0);
                $report['totals']['skipped'] += (int) ($item['skipped'] ?? 0);
                $report['totals']['errors'] += count($item['errors'] ?? []);
                $report['totals']['warnings'] += count($item['warnings'] ?? []);
                $report['totals']['unmappable'] += count($item['unmappable'] ?? []);
            }

            return $report;
        }

        $previousNaturalMatching = $this->disableNaturalMatching;
        $this->disableNaturalMatching = true;

        try {
            DB::transaction(function () use (&$report, $groups, $sourceConnection, $clearTables): void {
                $this->clearTargetTables($clearTables);

                foreach ($groups as $group) {
                    $item = $this->importGroup($sourceConnection, $group, false);
                    $report['items'][$group] = $item;
                    $report['totals']['source_records'] += (int) ($item['source_records'] ?? 0);
                    $report['totals']['created'] += (int) ($item['created'] ?? 0);
                    $report['totals']['updated'] += (int) ($item['updated'] ?? 0);
                    $report['totals']['skipped'] += (int) ($item['skipped'] ?? 0);
                    $report['totals']['errors'] += count($item['errors'] ?? []);
                    $report['totals']['warnings'] += count($item['warnings'] ?? []);
                    $report['totals']['unmappable'] += count($item['unmappable'] ?? []);
                }
            }, 1);
        } finally {
            $this->disableNaturalMatching = $previousNaturalMatching;
        }

        return $report;
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    public function normalizeGroups(array $groups): array
    {
        if ($groups === []) {
            return self::DEFAULT_GROUPS;
        }

        $expanded = [];

        foreach ($groups as $group) {
            foreach (explode(',', (string) $group) as $chunk) {
                $value = trim($chunk);

                if ($value !== '') {
                    $expanded[] = $value;
                }
            }
        }

        $allowed = self::DEFAULT_GROUPS;

        return array_values(array_intersect($allowed, array_unique($expanded)));
    }

    protected function resolveSourceConnection(): ?ConnectionInterface
    {
        if (! $this->sourceConnectionConfigured()) {
            return null;
        }

        try {
            $connection = DB::connection(self::SOURCE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, array{columns:list<string>, primary_key:list<string>, foreign_keys:list<array{column:string, references_table:string, references_column:string}>}>  $dumpTables
     * @param  list<string>  $groups
     * @return array<string,mixed>
     */
    protected function buildAnalysis(array $dumpTables, array $groups, bool $sourceAvailable): array
    {
        $recognizedTables = array_keys($dumpTables);
        sort($recognizedTables);

        $groupMap = [];

        foreach ($groups as $group) {
            $groupMap[$group] = [
                'source_tables' => self::GROUP_SOURCE_TABLES[$group] ?? [],
                'target_tables' => self::GROUP_TARGET_TABLES[$group] ?? [],
            ];
        }

        return [
            'source_dump_path' => $this->sourceDumpPath(),
            'source_connection' => self::SOURCE_CONNECTION,
            'source_connection_available' => $sourceAvailable,
            'recognized_source_tables' => $recognizedTables,
            'recognized_table_columns' => array_map(
                static fn (array $table): array => $table['columns'],
                $dumpTables,
            ),
            'group_map' => $groupMap,
            'non_importable_source_tables' => self::NON_IMPORTABLE_SOURCE_TABLES,
            'assumptions' => [
                'Le vecchie service_categories vengono importate sia nelle aree operative (service_categories) sia nelle specializzazioni editoriali (specializations).',
                'Le relazioni professional_service_categories vengono replicate sia come aree operative sia come professional_specialization con is_primary=false, perche la sorgente non ha un flag primario.',
                'I riferimenti created_by, updated_by e launched_by del vecchio DB non vengono importati automaticamente: se non esiste una mappatura utenti rimangono null.',
                'Le impostazioni eliminate dalla nuova struttura (reminder_dates e quarter_shortcuts) vengono conservate dentro general_preferences.old_core_import.',
                'I promemoria non weekly vengono saltati perche la struttura attuale li ha rimossi volutamente.',
                'Le tabelle tecniche di cache, sessione, job, token e audit non vengono importate automaticamente.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function importGroup(ConnectionInterface $sourceConnection, string $group, bool $dryRun): array
    {
        return match ($group) {
            'specializations' => $this->importSpecializations($sourceConnection, $dryRun),
            'professionals' => $this->importProfessionals($sourceConnection, $dryRun),
            'patients' => $this->importPatients($sourceConnection, $dryRun),
            'services' => $this->importServices($sourceConnection, $dryRun),
            'performance-records' => $this->importPerformanceRecords($sourceConnection, $dryRun),
            'expenses' => $this->importExpenses($sourceConnection, $dryRun),
            'marketing' => $this->importMarketing($sourceConnection, $dryRun),
            'settings' => $this->importSettings($sourceConnection, $dryRun),
            default => $this->emptyItem($group),
        };
    }

    protected function simulateRebuildGroup(ConnectionInterface $sourceConnection, string $group): array
    {
        return match ($group) {
            'specializations' => $this->simulateSpecializations($sourceConnection),
            'professionals' => $this->simulateProfessionals($sourceConnection),
            'patients' => $this->simulatePatients($sourceConnection),
            'services' => $this->simulateServices($sourceConnection),
            'performance-records' => $this->simulatePerformanceRecords($sourceConnection),
            'expenses' => $this->simulateExpenses($sourceConnection),
            'marketing' => $this->simulateMarketing($sourceConnection),
            'settings' => $this->simulateSettings($sourceConnection),
            default => $this->emptyItem($group),
        };
    }

    protected function importSpecializations(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $rows = $this->fetchRows($sourceConnection, 'service_categories');
        $item = $this->baseItem('specializations', count($rows));

        foreach ($rows as $row) {
            $normalizedSlug = $this->normalizeSlug($row['slug'] ?? null, $row['name'] ?? null);
            $sortOrder = (int) ($row['sort_order'] ?? 0);

            [$category, $categoryCreated] = $this->resolveTargetModel(
                entityType: 'service_category',
                oldTable: 'service_categories',
                oldId: (int) $row['id'],
                modelClass: ServiceCategory::class,
                naturalResolver: fn (): ?Model => ServiceCategory::query()
                    ->where('slug', $normalizedSlug)
                    ->orWhere('name', $row['name'])
                    ->first(),
            );

            $categoryPayload = [
                'name' => (string) $row['name'],
                'slug' => $normalizedSlug,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $sortOrder,
            ];

            if ($categoryCreated) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $category->forceFill($categoryPayload)->save();
                $this->storeMapping(
                    'service_category',
                    'service_categories',
                    (int) $row['id'],
                    $category->getTable(),
                    (int) $category->getKey(),
                    $row,
                );
            }

            [$specialization, $specializationCreated] = $this->resolveTargetModel(
                entityType: 'specialization',
                oldTable: 'service_categories',
                oldId: (int) $row['id'],
                modelClass: Specialization::class,
                naturalResolver: fn (): ?Model => Specialization::query()
                    ->where('slug', $normalizedSlug)
                    ->orWhere('name', $row['name'])
                    ->first(),
            );

            $specializationPayload = [
                'name' => (string) $row['name'],
                'slug' => $normalizedSlug,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'sort_order' => $sortOrder,
            ];

            if ($specializationCreated) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $specialization->forceFill($specializationPayload)->save();
                $this->storeMapping(
                    'specialization',
                    'service_categories',
                    (int) $row['id'],
                    $specialization->getTable(),
                    (int) $specialization->getKey(),
                    $row,
                );
            }
        }

        return $item;
    }

    protected function importProfessionals(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $rows = $this->fetchRows($sourceConnection, 'professionals');
        $pivotRows = $this->fetchRows($sourceConnection, 'professional_service_categories');
        $item = $this->baseItem('professionals', count($rows) + count($pivotRows));

        foreach ($rows as $row) {
            [$professional, $created] = $this->resolveTargetModel(
                entityType: 'professional',
                oldTable: 'professionals',
                oldId: (int) $row['id'],
                modelClass: Professional::class,
                naturalResolver: fn (): ?Model => $this->matchProfessional($row),
            );

            $payload = [
                'subject_type' => in_array(($row['subject_type'] ?? null), [
                    ProfessionalSubjectType::Individual->value,
                    ProfessionalSubjectType::Company->value,
                ], true)
                    ? $row['subject_type']
                    : ProfessionalSubjectType::Individual->value,
                'first_name' => $this->nullableString($row['first_name'] ?? null),
                'last_name' => $this->nullableString($row['last_name'] ?? null),
                'company_name' => $this->nullableString($row['company_name'] ?? null),
                'full_name' => $this->safeString($row['full_name'] ?? null),
                'area_name' => $this->safeString($row['area_name'] ?? null),
                'email' => $this->nullableString($row['email'] ?? null),
                'iban' => $this->nullableString($row['iban'] ?? null),
                'avatar_path' => $this->nullableString($row['avatar_path'] ?? null),
                'is_active' => (bool) ($row['is_active'] ?? true),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ];

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $professional->forceFill($payload)->save();
                $this->storeMapping('professional', 'professionals', (int) $row['id'], $professional->getTable(), (int) $professional->getKey(), $row);
            }
        }

        foreach ($pivotRows as $row) {
            $professionalId = $this->mappedTargetId('professional', 'professionals', (int) $row['professional_id']);
            $serviceCategoryId = $this->mappedTargetId('service_category', 'service_categories', (int) $row['service_category_id']);
            $specializationId = $this->mappedTargetId('specialization', 'service_categories', (int) $row['service_category_id']);

            if ($professionalId === null || $serviceCategoryId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf(
                    'professional_service_categories #%d non importabile: professional_id=%s service_category_id=%s',
                    (int) $row['id'],
                    (string) $row['professional_id'],
                    (string) $row['service_category_id'],
                );

                continue;
            }

            $existingArea = DB::table('professional_service_categories')
                ->where('professional_id', $professionalId)
                ->where('service_category_id', $serviceCategoryId)
                ->exists();

            if ($existingArea) {
                $item['updated']++;
            } else {
                $item['created']++;
            }

            if (! $dryRun) {
                DB::table('professional_service_categories')->updateOrInsert(
                    [
                        'professional_id' => $professionalId,
                        'service_category_id' => $serviceCategoryId,
                    ],
                    [
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                        'created_at' => $row['created_at'] ?? now(),
                        'updated_at' => $row['updated_at'] ?? now(),
                    ],
                );

                $pivotId = DB::table('professional_service_categories')
                    ->where('professional_id', $professionalId)
                    ->where('service_category_id', $serviceCategoryId)
                    ->value('id');

                $this->storeMapping(
                    'professional_service_category',
                    'professional_service_categories',
                    (int) $row['id'],
                    'professional_service_categories',
                    (int) $pivotId,
                    $row,
                );
            }

            if ($specializationId !== null) {
                $existingSpecialization = DB::table('professional_specialization')
                    ->where('professional_id', $professionalId)
                    ->where('specialization_id', $specializationId)
                    ->exists();

                if ($existingSpecialization) {
                    $item['updated']++;
                } else {
                    $item['created']++;
                }

                if (! $dryRun) {
                    DB::table('professional_specialization')->updateOrInsert(
                        [
                            'professional_id' => $professionalId,
                            'specialization_id' => $specializationId,
                        ],
                        [
                            'is_primary' => false,
                            'sort_order' => (int) ($row['sort_order'] ?? 0),
                            'created_at' => $row['created_at'] ?? now(),
                            'updated_at' => $row['updated_at'] ?? now(),
                        ],
                    );
                }
            }
        }

        return $item;
    }

    protected function importPatients(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $rows = $this->fetchRows($sourceConnection, 'patients');
        $item = $this->baseItem('patients', count($rows));

        foreach ($rows as $row) {
            [$patient, $created, $ambiguous] = $this->resolvePatient($row);

            if ($ambiguous) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Paziente old #%d non importabile: match ambiguo sui dati anagrafici.', (int) $row['id']);

                continue;
            }

            $payload = [
                'first_name' => $this->safeString($row['first_name'] ?? null),
                'last_name' => $this->safeString($row['last_name'] ?? null),
                'full_name' => $this->safeString($row['full_name'] ?? null),
                'tax_code' => $this->nullableString($row['tax_code'] ?? null),
                'sex' => $this->nullableString($row['sex'] ?? null),
                'birth_date' => $row['birth_date'] ?? null,
                'year_of_birth' => $row['year_of_birth'] ?? null,
                'phone' => $this->nullableString($row['phone'] ?? null),
                'email' => $this->nullableString($row['email'] ?? null),
                'residence_address' => $this->nullableString($row['residence_address'] ?? null),
                'residence_city' => $this->nullableString($row['residence_city'] ?? null),
                'residence_zip' => $this->nullableString($row['residence_zip'] ?? null),
                'residence_latitude' => $row['residence_latitude'] ?? null,
                'residence_longitude' => $row['residence_longitude'] ?? null,
                'geocoding_status' => $this->nullableString($row['geocoding_status'] ?? null),
                'geocoded_at' => $row['geocoded_at'] ?? null,
                'contactable_sms' => (bool) ($row['contactable_sms'] ?? true),
                'contactable_email' => (bool) ($row['contactable_email'] ?? true),
                'excluded_from_campaigns' => (bool) ($row['excluded_from_campaigns'] ?? false),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ];

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $patient->forceFill($payload)->save();
                $this->storeMapping('patient', 'patients', (int) $row['id'], $patient->getTable(), (int) $patient->getKey(), $row);
            }
        }

        return $item;
    }

    protected function importServices(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $serviceRows = $this->fetchRows($sourceConnection, 'services');
        $aliasRows = $this->fetchRows($sourceConnection, 'service_aliases');
        $professionalServiceRows = $this->fetchRows($sourceConnection, 'professional_services');
        $item = $this->baseItem('services', count($serviceRows) + count($aliasRows) + count($professionalServiceRows));

        foreach ($serviceRows as $row) {
            $categoryId = null;

            if (! empty($row['category_id'])) {
                $categoryId = $this->mappedTargetId('service_category', 'service_categories', (int) $row['category_id']);

                if ($categoryId === null) {
                    $item['warnings'][] = sprintf('Servizio old #%d: categoria #%d non mappata, category_id impostato a null.', (int) $row['id'], (int) $row['category_id']);
                }
            }

            [$service, $created] = $this->resolveTargetModel(
                entityType: 'service',
                oldTable: 'services',
                oldId: (int) $row['id'],
                modelClass: Service::class,
                naturalResolver: fn (): ?Model => Service::query()
                    ->where('slug', $this->normalizeSlug($row['slug'] ?? null, $row['display_name'] ?? $row['canonical_name'] ?? null))
                    ->orWhere(function (Builder $query) use ($row): void {
                        $query->where('canonical_name', $row['canonical_name'])
                            ->where('display_name', $row['display_name']);
                    })
                    ->first(),
            );

            $payload = [
                'category_id' => $categoryId,
                'canonical_name' => $this->safeString($row['canonical_name'] ?? null),
                'display_name' => $this->safeString($row['display_name'] ?? null),
                'importo_prestazione' => $row['importo_prestazione'] ?? null,
                'slug' => $this->normalizeSlug($row['slug'] ?? null, $row['display_name'] ?? $row['canonical_name'] ?? null),
                'description' => $this->nullableString($row['description'] ?? null),
                'default_duration_minutes' => $row['default_duration_minutes'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ];

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $service->forceFill($payload)->save();
                $this->storeMapping('service', 'services', (int) $row['id'], $service->getTable(), (int) $service->getKey(), $row);

                $specializationId = ! empty($row['category_id'])
                    ? $this->mappedTargetId('specialization', 'service_categories', (int) $row['category_id'])
                    : null;

                if ($specializationId !== null) {
                    $existingServiceSpecialization = DB::table('service_specialization')
                        ->where('service_id', $service->getKey())
                        ->where('specialization_id', $specializationId)
                        ->exists();

                    if ($existingServiceSpecialization) {
                        $item['updated']++;
                    } else {
                        $item['created']++;
                    }

                    DB::table('service_specialization')->updateOrInsert(
                        [
                            'service_id' => $service->getKey(),
                            'specialization_id' => $specializationId,
                        ],
                        [
                            'is_primary' => true,
                            'sort_order' => 0,
                            'created_at' => $row['created_at'] ?? now(),
                            'updated_at' => $row['updated_at'] ?? now(),
                        ],
                    );
                }
            }
        }

        foreach ($aliasRows as $row) {
            $serviceId = $this->mappedTargetId('service', 'services', (int) $row['service_id']);

            if ($serviceId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Alias servizio old #%d non importabile: service_id=%s non mappato.', (int) $row['id'], (string) $row['service_id']);

                continue;
            }

            [$alias, $created] = $this->resolveTargetModel(
                entityType: 'service_alias',
                oldTable: 'service_aliases',
                oldId: (int) $row['id'],
                modelClass: ServiceAlias::class,
                naturalResolver: fn (): ?Model => ServiceAlias::query()
                    ->where('service_id', $serviceId)
                    ->where('alias_slug', $this->normalizeSlug($row['alias_slug'] ?? null, $row['alias_name'] ?? null))
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $alias->forceFill([
                    'service_id' => $serviceId,
                    'alias_name' => $this->safeString($row['alias_name'] ?? null),
                    'alias_slug' => $this->normalizeSlug($row['alias_slug'] ?? null, $row['alias_name'] ?? null),
                    'source_label' => $this->nullableString($row['source_label'] ?? null),
                ])->save();

                $this->storeMapping('service_alias', 'service_aliases', (int) $row['id'], $alias->getTable(), (int) $alias->getKey(), $row);
            }
        }

        foreach ($professionalServiceRows as $row) {
            $professionalId = $this->mappedTargetId('professional', 'professionals', (int) $row['professional_id']);
            $serviceId = $this->mappedTargetId('service', 'services', (int) $row['service_id']);

            if ($professionalId === null || $serviceId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('professional_services #%d non importabile: professional_id=%s service_id=%s', (int) $row['id'], (string) $row['professional_id'], (string) $row['service_id']);

                continue;
            }

            [$professionalService, $created] = $this->resolveTargetModel(
                entityType: 'professional_service',
                oldTable: 'professional_services',
                oldId: (int) $row['id'],
                modelClass: ProfessionalService::class,
                naturalResolver: fn (): ?Model => ProfessionalService::query()
                    ->where('professional_id', $professionalId)
                    ->where('service_id', $serviceId)
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $professionalService->forceFill([
                    'professional_id' => $professionalId,
                    'service_id' => $serviceId,
                    'duration_minutes' => $row['duration_minutes'] ?? null,
                    'price_amount' => $row['price_amount'] ?? null,
                    'is_visible_public' => (bool) ($row['is_visible_public'] ?? true),
                    'is_bookable_online' => (bool) ($row['is_bookable_online'] ?? false),
                    'source_platform' => $this->nullableString($row['source_platform'] ?? null),
                    'source_notes' => $this->nullableString($row['source_notes'] ?? null),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ])->save();

                $this->storeMapping('professional_service', 'professional_services', (int) $row['id'], $professionalService->getTable(), (int) $professionalService->getKey(), $row);
            }
        }

        return $item;
    }

    protected function importPerformanceRecords(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $recordRows = $this->fetchRows($sourceConnection, 'performance_records');
        $pivotRows = $this->fetchRows($sourceConnection, 'patient_performance_record');
        $splitRows = $this->fetchRows($sourceConnection, 'performance_record_splits');
        $item = $this->baseItem('performance-records', count($recordRows) + count($pivotRows) + count($splitRows));

        foreach ($recordRows as $row) {
            $professionalId = $this->mappedTargetId('professional', 'professionals', (int) $row['professional_id']);

            if ($professionalId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Prestazione effettuata old #%d non importabile: professional_id=%s non mappato.', (int) $row['id'], (string) $row['professional_id']);

                continue;
            }

            $patientId = null;
            if (! empty($row['patient_id'])) {
                $patientId = $this->mappedTargetId('patient', 'patients', (int) $row['patient_id']);

                if ($patientId === null) {
                    $item['warnings'][] = sprintf('Prestazione effettuata old #%d: patient_id=%d non mappato, relazione principale impostata a null.', (int) $row['id'], (int) $row['patient_id']);
                }
            }

            $serviceId = null;
            if (! empty($row['service_id'])) {
                $serviceId = $this->mappedTargetId('service', 'services', (int) $row['service_id']);

                if ($serviceId === null) {
                    $item['warnings'][] = sprintf('Prestazione effettuata old #%d: service_id=%d non mappato, service_id impostato a null.', (int) $row['id'], (int) $row['service_id']);
                }
            }

            [$record, $created] = $this->resolveTargetModel(
                entityType: 'performance_record',
                oldTable: 'performance_records',
                oldId: (int) $row['id'],
                modelClass: PerformanceRecord::class,
                naturalResolver: fn (): ?Model => PerformanceRecord::query()
                    ->where('performed_at', $row['performed_at'])
                    ->where('professional_id', $professionalId)
                    ->where('service_name_snapshot', $row['service_name_snapshot'])
                    ->where('total_amount', $row['total_amount'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $record->forceFill([
                    'performed_at' => $row['performed_at'],
                    'patient_id' => $patientId,
                    'professional_id' => $professionalId,
                    'professional_name_snapshot' => $this->safeString($row['professional_name_snapshot'] ?? null),
                    'category_name_snapshot' => $this->nullableString($row['category_name_snapshot'] ?? null),
                    'service_id' => $serviceId,
                    'service_name_snapshot' => $this->safeString($row['service_name_snapshot'] ?? null),
                    'quantity' => $row['quantity'],
                    'unit_amount' => $row['unit_amount'],
                    'total_amount' => $row['total_amount'],
                    'direct_cost' => $row['direct_cost'] ?? 0,
                    'calculation_mode' => $row['calculation_mode'],
                    'split_mode' => $row['split_mode'] ?? 'standard',
                    'percentage_value' => $row['percentage_value'] ?? null,
                    'fixed_amount' => $row['fixed_amount'] ?? null,
                    'professional_amount' => $row['professional_amount'],
                    'center_amount' => $row['center_amount'],
                    'payment_method' => $row['payment_method'] ?? 'card',
                    'payment_status' => $row['payment_status'] ?? 'da_pagare',
                    'is_invoiced' => (bool) ($row['is_invoiced'] ?? false),
                    'is_black' => (bool) ($row['is_black'] ?? false),
                    'is_promo' => (bool) ($row['is_promo'] ?? false),
                    'notes' => $this->nullableString($row['notes'] ?? null),
                ])->save();

                $this->storeMapping('performance_record', 'performance_records', (int) $row['id'], $record->getTable(), (int) $record->getKey(), $row);

                if ($patientId !== null && ! $this->disableNaturalMatching) {
                    DB::table('patient_performance_record')->updateOrInsert(
                        [
                            'patient_id' => $patientId,
                            'performance_record_id' => $record->getKey(),
                        ],
                        [
                            'sort_order' => 0,
                        ],
                    );
                }
            }
        }

        foreach ($pivotRows as $row) {
            $patientId = $this->mappedTargetId('patient', 'patients', (int) $row['patient_id']);
            $recordId = $this->mappedTargetId('performance_record', 'performance_records', (int) $row['performance_record_id']);

            if ($patientId === null || $recordId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('patient_performance_record old patient_id=%s performance_record_id=%s non importabile.', (string) $row['patient_id'], (string) $row['performance_record_id']);

                continue;
            }

            $exists = DB::table('patient_performance_record')
                ->where('patient_id', $patientId)
                ->where('performance_record_id', $recordId)
                ->exists();

            if ($exists) {
                $item['updated']++;
            } else {
                $item['created']++;
            }

            if (! $dryRun) {
                DB::table('patient_performance_record')->updateOrInsert(
                    [
                        'patient_id' => $patientId,
                        'performance_record_id' => $recordId,
                    ],
                    [
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                    ],
                );
            }
        }

        foreach ($splitRows as $row) {
            $recordId = $this->mappedTargetId('performance_record', 'performance_records', (int) $row['performance_record_id']);

            if ($recordId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('performance_record_splits #%d non importabile: performance_record_id=%s non mappato.', (int) $row['id'], (string) $row['performance_record_id']);

                continue;
            }

            $professionalId = null;
            if (! empty($row['professional_id'])) {
                $professionalId = $this->mappedTargetId('professional', 'professionals', (int) $row['professional_id']);
            }

            [$split, $created] = $this->resolveTargetModel(
                entityType: 'performance_record_split',
                oldTable: 'performance_record_splits',
                oldId: (int) $row['id'],
                modelClass: PerformanceRecordSplit::class,
                naturalResolver: fn (): ?Model => PerformanceRecordSplit::query()
                    ->where('performance_record_id', $recordId)
                    ->where('subject_type', $row['subject_type'])
                    ->where('sort_order', (int) ($row['sort_order'] ?? 0))
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $split->forceFill([
                    'performance_record_id' => $recordId,
                    'subject_type' => $row['subject_type'],
                    'professional_id' => $professionalId,
                    'professional_name_snapshot' => $this->nullableString($row['professional_name_snapshot'] ?? null),
                    'amount' => $row['amount'],
                    'description' => $this->nullableString($row['description'] ?? null),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ])->save();

                $this->storeMapping('performance_record_split', 'performance_record_splits', (int) $row['id'], $split->getTable(), (int) $split->getKey(), $row);
            }
        }

        return $item;
    }

    protected function importExpenses(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $categoryRows = $this->fetchRows($sourceConnection, 'expense_categories');
        $templateRows = $this->fetchRows($sourceConnection, 'expense_templates');
        $recordRows = $this->fetchRows($sourceConnection, 'expense_records');
        $competenceRows = $this->fetchRows($sourceConnection, 'expense_record_competences');
        $cashRows = $this->fetchRows($sourceConnection, 'cash_movements');
        $item = $this->baseItem('expenses', count($categoryRows) + count($templateRows) + count($recordRows) + count($competenceRows) + count($cashRows));

        foreach ($categoryRows as $row) {
            [$category, $created] = $this->resolveTargetModel(
                entityType: 'expense_category',
                oldTable: 'expense_categories',
                oldId: (int) $row['id'],
                modelClass: ExpenseCategory::class,
                naturalResolver: fn (): ?Model => ExpenseCategory::query()
                    ->where('slug', $this->normalizeSlug($row['slug'] ?? null, $row['name'] ?? null))
                    ->orWhere('name', $row['name'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $category->forceFill([
                    'name' => $this->safeString($row['name'] ?? null),
                    'slug' => $this->normalizeSlug($row['slug'] ?? null, $row['name'] ?? null),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ])->save();

                $this->storeMapping('expense_category', 'expense_categories', (int) $row['id'], $category->getTable(), (int) $category->getKey(), $row);
            }
        }

        foreach ($templateRows as $row) {
            $categoryId = $this->mappedTargetId('expense_category', 'expense_categories', (int) $row['category_id']);

            if ($categoryId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Template costo old #%d non importabile: category_id=%s non mappato.', (int) $row['id'], (string) $row['category_id']);

                continue;
            }

            [$template, $created] = $this->resolveTargetModel(
                entityType: 'expense_template',
                oldTable: 'expense_templates',
                oldId: (int) $row['id'],
                modelClass: ExpenseTemplate::class,
                naturalResolver: fn (): ?Model => ExpenseTemplate::query()
                    ->where('category_id', $categoryId)
                    ->where('name', $row['name'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $template->forceFill([
                    'category_id' => $categoryId,
                    'name' => $this->safeString($row['name'] ?? null),
                    'type' => $row['type'],
                    'recurrence' => $row['recurrence'],
                    'default_amount' => $row['default_amount'],
                    'start_date' => $row['start_date'] ?? null,
                    'end_date' => $row['end_date'] ?? null,
                    'day_of_generation' => $row['day_of_generation'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'notes' => $this->nullableString($row['notes'] ?? null),
                ])->save();

                $this->storeMapping('expense_template', 'expense_templates', (int) $row['id'], $template->getTable(), (int) $template->getKey(), $row);
            }
        }

        foreach ($recordRows as $row) {
            $categoryId = $this->mappedTargetId('expense_category', 'expense_categories', (int) $row['expense_category_id']);

            if ($categoryId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Costo old #%d non importabile: expense_category_id=%s non mappato.', (int) $row['id'], (string) $row['expense_category_id']);

                continue;
            }

            $templateId = ! empty($row['expense_template_id'])
                ? $this->mappedTargetId('expense_template', 'expense_templates', (int) $row['expense_template_id'])
                : null;
            $performanceRecordId = ! empty($row['source_performance_record_id'])
                ? $this->mappedTargetId('performance_record', 'performance_records', (int) $row['source_performance_record_id'])
                : null;

            [$record, $created] = $this->resolveTargetModel(
                entityType: 'expense_record',
                oldTable: 'expense_records',
                oldId: (int) $row['id'],
                modelClass: ExpenseRecord::class,
                naturalResolver: fn (): ?Model => ExpenseRecord::query()
                    ->when($performanceRecordId !== null && filled($row['generation_key'] ?? null), fn (Builder $query) => $query
                        ->where('source_performance_record_id', $performanceRecordId)
                        ->where('generation_key', $row['generation_key']))
                    ->when(blank($row['generation_key'] ?? null), fn (Builder $query) => $query
                        ->where('expense_date', $row['expense_date'])
                        ->where('description', $row['description'])
                        ->where('amount', $row['amount']))
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                EloquentModel::withoutEvents(function () use ($record, $categoryId, $templateId, $performanceRecordId, $row): void {
                    $record->forceFill([
                        'expense_category_id' => $categoryId,
                        'expense_template_id' => $templateId,
                        'source_performance_record_id' => $performanceRecordId,
                        'source' => $row['source'] ?? 'manual',
                        'generation_key' => $this->nullableString($row['generation_key'] ?? null),
                        'expense_date' => $row['expense_date'],
                        'competence_start_date' => $row['competence_start_date'] ?? null,
                        'competence_end_date' => $row['competence_end_date'] ?? null,
                        'competence_months_count' => $row['competence_months_count'] ?? 1,
                        'competence_month' => $row['competence_month'],
                        'competence_year' => $row['competence_year'],
                        'description' => $this->safeString($row['description'] ?? null),
                        'type' => $row['type'],
                        'amount' => $row['amount'],
                        'supplier' => $this->nullableString($row['supplier'] ?? null),
                        'payment_status' => $row['payment_status'] ?? 'pagata',
                        'notes' => $this->nullableString($row['notes'] ?? null),
                    ])->save();
                });

                // The rebuild/import uses old_core.expense_record_competences as the
                // only source of truth. If domain observers generated allocations,
                // remove them before importing the source rows explicitly.
                $record->competenceAllocations()->delete();

                $this->storeMapping('expense_record', 'expense_records', (int) $row['id'], $record->getTable(), (int) $record->getKey(), $row);
            }
        }

        foreach ($competenceRows as $row) {
            $expenseRecordId = $this->mappedTargetId('expense_record', 'expense_records', (int) $row['expense_record_id']);

            if ($expenseRecordId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Competenza costo old #%d non importabile: expense_record_id=%s non mappato.', (int) $row['id'], (string) $row['expense_record_id']);

                continue;
            }

            [$competence, $created] = $this->resolveTargetModel(
                entityType: 'expense_record_competence',
                oldTable: 'expense_record_competences',
                oldId: (int) $row['id'],
                modelClass: ExpenseRecordCompetence::class,
                naturalResolver: fn (): ?Model => ExpenseRecordCompetence::query()
                    ->where('expense_record_id', $expenseRecordId)
                    ->where('competence_year', $row['competence_year'])
                    ->where('competence_month', $row['competence_month'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $competence->forceFill([
                    'expense_record_id' => $expenseRecordId,
                    'competence_date' => $row['competence_date'],
                    'competence_month' => $row['competence_month'],
                    'competence_year' => $row['competence_year'],
                    'allocated_amount' => $row['allocated_amount'],
                ])->save();

                $this->storeMapping('expense_record_competence', 'expense_record_competences', (int) $row['id'], $competence->getTable(), (int) $competence->getKey(), $row);
            }
        }

        foreach ($cashRows as $row) {
            $performanceRecordId = ! empty($row['source_performance_record_id'])
                ? $this->mappedTargetId('performance_record', 'performance_records', (int) $row['source_performance_record_id'])
                : null;

            [$cashMovement, $created] = $this->resolveTargetModel(
                entityType: 'cash_movement',
                oldTable: 'cash_movements',
                oldId: (int) $row['id'],
                modelClass: CashMovement::class,
                naturalResolver: fn (): ?Model => CashMovement::query()
                    ->when($performanceRecordId !== null, fn (Builder $query) => $query->where('source_performance_record_id', $performanceRecordId))
                    ->when($performanceRecordId === null, fn (Builder $query) => $query
                        ->where('movement_date', $row['movement_date'])
                        ->where('cash_box_type', $row['cash_box_type'])
                        ->where('movement_type', $row['movement_type'])
                        ->where('counterparty_name', $row['counterparty_name'])
                        ->where('amount', $row['amount']))
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $cashMovement->forceFill([
                    'movement_date' => $row['movement_date'],
                    'cash_box_type' => $row['cash_box_type'],
                    'movement_type' => $row['movement_type'],
                    'counterparty_name' => $this->safeString($row['counterparty_name'] ?? null),
                    'amount' => $row['amount'],
                    'reason' => $this->nullableString($row['reason'] ?? null),
                    'notes' => $this->nullableString($row['notes'] ?? null),
                    'source_performance_record_id' => $performanceRecordId,
                    'balance_after' => $row['balance_after'] ?? 0,
                ])->save();

                $this->storeMapping('cash_movement', 'cash_movements', (int) $row['id'], $cashMovement->getTable(), (int) $cashMovement->getKey(), $row);
            }
        }

        return $item;
    }

    protected function importMarketing(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $segmentRows = $this->fetchRows($sourceConnection, 'marketing_segments');
        $recipientRows = $this->fetchRows($sourceConnection, 'marketing_segment_manual_recipients');
        $campaignRows = $this->fetchRows($sourceConnection, 'marketing_campaigns');
        $deliveryRows = $this->fetchRows($sourceConnection, 'marketing_campaign_deliveries');
        $item = $this->baseItem('marketing', count($segmentRows) + count($recipientRows) + count($campaignRows) + count($deliveryRows));

        foreach ($segmentRows as $row) {
            [$segment, $created] = $this->resolveTargetModel(
                entityType: 'marketing_segment',
                oldTable: 'marketing_segments',
                oldId: (int) $row['id'],
                modelClass: MarketingSegment::class,
                naturalResolver: fn (): ?Model => MarketingSegment::query()
                    ->where('name', $row['name'])
                    ->where('segment_type', $row['segment_type'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $segment->forceFill([
                    'name' => $this->safeString($row['name'] ?? null),
                    'description' => $this->nullableString($row['description'] ?? null),
                    'segment_type' => $this->safeString($row['segment_type'] ?? null),
                    'filters' => $this->decodeJsonToArray($row['filters'] ?? null),
                    'last_preview_count' => (int) ($row['last_preview_count'] ?? 0),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'created_by' => null,
                    'updated_by' => null,
                ])->save();

                $this->storeMapping('marketing_segment', 'marketing_segments', (int) $row['id'], $segment->getTable(), (int) $segment->getKey(), $row);
            }
        }

        foreach ($recipientRows as $row) {
            $segmentId = $this->mappedTargetId('marketing_segment', 'marketing_segments', (int) $row['marketing_segment_id']);

            if ($segmentId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Manual recipient old #%d non importabile: marketing_segment_id=%s non mappato.', (int) $row['id'], (string) $row['marketing_segment_id']);

                continue;
            }

            $patientId = ! empty($row['patient_id'])
                ? $this->mappedTargetId('patient', 'patients', (int) $row['patient_id'])
                : null;

            [$recipient, $created] = $this->resolveTargetModel(
                entityType: 'marketing_segment_manual_recipient',
                oldTable: 'marketing_segment_manual_recipients',
                oldId: (int) $row['id'],
                modelClass: MarketingSegmentManualRecipient::class,
                naturalResolver: fn (): ?Model => MarketingSegmentManualRecipient::query()
                    ->where('marketing_segment_id', $segmentId)
                    ->where('normalized_phone', $row['normalized_phone'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $recipient->forceFill([
                    'marketing_segment_id' => $segmentId,
                    'patient_id' => $patientId,
                    'original_value' => $this->safeString($row['original_value'] ?? null),
                    'normalized_phone' => $this->safeString($row['normalized_phone'] ?? null),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ])->save();

                $this->storeMapping('marketing_segment_manual_recipient', 'marketing_segment_manual_recipients', (int) $row['id'], $recipient->getTable(), (int) $recipient->getKey(), $row);
            }
        }

        foreach ($campaignRows as $row) {
            if (! in_array(($row['channel'] ?? null), ['sms', 'email', 'all'], true)) {
                $item['skipped']++;

                continue;
            }

            $segmentId = $this->mappedTargetId('marketing_segment', 'marketing_segments', (int) $row['marketing_segment_id']);

            if ($segmentId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Campagna old #%d non importabile: marketing_segment_id=%s non mappato.', (int) $row['id'], (string) $row['marketing_segment_id']);

                continue;
            }

            [$campaign, $created] = $this->resolveTargetModel(
                entityType: 'marketing_campaign',
                oldTable: 'marketing_campaigns',
                oldId: (int) $row['id'],
                modelClass: MarketingCampaign::class,
                naturalResolver: fn (): ?Model => MarketingCampaign::query()
                    ->where('marketing_segment_id', $segmentId)
                    ->where('name', $row['name'])
                    ->where('channel', $row['channel'])
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $campaign->forceFill([
                    'name' => $this->safeString($row['name'] ?? null),
                    'marketing_segment_id' => $segmentId,
                    'channel' => $row['channel'],
                    'template_key' => $this->nullableString($row['template_key'] ?? null),
                    'subject' => $this->nullableString($row['subject'] ?? null),
                    'message' => $this->safeString($row['message'] ?? null),
                    'status' => $row['status'],
                    'scheduled_at' => $row['scheduled_at'] ?? null,
                    'dispatched_at' => $row['dispatched_at'] ?? null,
                    'completed_at' => $row['completed_at'] ?? null,
                    'recipients_count' => (int) ($row['recipients_count'] ?? 0),
                    'sent_count' => (int) ($row['sent_count'] ?? 0),
                    'failed_count' => (int) ($row['failed_count'] ?? 0),
                    'excluded_count' => (int) ($row['excluded_count'] ?? 0),
                    'last_test_sent_at' => $row['last_test_sent_at'] ?? null,
                    'created_by' => null,
                    'updated_by' => null,
                    'launched_by' => null,
                ])->save();

                $this->storeMapping('marketing_campaign', 'marketing_campaigns', (int) $row['id'], $campaign->getTable(), (int) $campaign->getKey(), $row);
            }
        }

        foreach ($deliveryRows as $row) {
            if (! in_array(($row['channel'] ?? null), ['sms', 'email'], true)) {
                $item['skipped']++;

                continue;
            }

            $campaignId = $this->mappedTargetId('marketing_campaign', 'marketing_campaigns', (int) $row['marketing_campaign_id']);

            if ($campaignId === null) {
                $item['skipped']++;
                $item['unmappable'][] = sprintf('Delivery old #%d non importabile: marketing_campaign_id=%s non mappato.', (int) $row['id'], (string) $row['marketing_campaign_id']);

                continue;
            }

            $patientId = ! empty($row['patient_id'])
                ? $this->mappedTargetId('patient', 'patients', (int) $row['patient_id'])
                : null;

            [$delivery, $created] = $this->resolveTargetModel(
                entityType: 'marketing_campaign_delivery',
                oldTable: 'marketing_campaign_deliveries',
                oldId: (int) $row['id'],
                modelClass: MarketingCampaignDelivery::class,
                naturalResolver: fn (): ?Model => MarketingCampaignDelivery::query()
                    ->where('marketing_campaign_id', $campaignId)
                    ->where('channel', $row['channel'])
                    ->where('target_value', $row['target_value'])
                    ->where('is_test', (bool) ($row['is_test'] ?? false))
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $delivery->forceFill([
                    'marketing_campaign_id' => $campaignId,
                    'patient_id' => $patientId,
                    'channel' => $row['channel'],
                    'is_test' => (bool) ($row['is_test'] ?? false),
                    'target_name' => $this->nullableString($row['target_name'] ?? null),
                    'target_value' => $this->safeString($row['target_value'] ?? null),
                    'delivery_status' => $row['delivery_status'],
                    'provider_message_id' => $this->nullableString($row['provider_message_id'] ?? null),
                    'provider_status' => $this->nullableString($row['provider_status'] ?? null),
                    'error_message' => $this->nullableString($row['error_message'] ?? null),
                    'provider_response' => $this->decodeJsonToArray($row['provider_response'] ?? null),
                    'sent_at' => $row['sent_at'] ?? null,
                ])->save();

                $this->storeMapping('marketing_campaign_delivery', 'marketing_campaign_deliveries', (int) $row['id'], $delivery->getTable(), (int) $delivery->getKey(), $row);
            }
        }

        return $item;
    }

    protected function importSettings(ConnectionInterface $sourceConnection, bool $dryRun): array
    {
        $settingsRows = $this->fetchRows($sourceConnection, 'application_settings');
        $reminderRows = $this->fetchRows($sourceConnection, 'reminders');
        $item = $this->baseItem('settings', count($settingsRows) + count($reminderRows));

        if ($settingsRows !== []) {
            $row = $settingsRows[0];
            $settings = ApplicationSetting::query()->first() ?? new ApplicationSetting;
            $generalPreferences = is_array($settings->general_preferences) ? $settings->general_preferences : [];

            $payload = [
                'reminder_email' => $this->nullableString($row['reminder_email'] ?? null),
                'quick_percentages' => $this->decodeJsonToArray($row['quick_percentages'] ?? null),
                'general_preferences' => array_merge($generalPreferences, $this->decodeJsonToArray($row['general_preferences'] ?? null), [
                    'old_core_import' => [
                        'reminder_dates' => $this->decodeJsonToArray($row['reminder_dates'] ?? null),
                        'quarter_shortcuts' => $this->decodeJsonToArray($row['quarter_shortcuts'] ?? null),
                    ],
                ]),
            ];

            if ($settings->exists) {
                $item['updated']++;
            } else {
                $item['created']++;
            }

            if (! $dryRun) {
                $settings->forceFill($payload)->save();
                $this->storeMapping('application_setting', 'application_settings', (int) $row['id'], $settings->getTable(), (int) $settings->getKey(), $row);
            }
        } else {
            $item['warnings'][] = 'Nessuna riga application_settings trovata nella sorgente old_core.';
        }

        foreach ($reminderRows as $row) {
            if (($row['frequency'] ?? null) !== 'weekly') {
                $item['skipped']++;
                $item['warnings'][] = sprintf('Promemoria old #%d saltato: frequency=%s non supportata nella struttura attuale.', (int) $row['id'], (string) ($row['frequency'] ?? 'n/a'));

                continue;
            }

            [$reminder, $created] = $this->resolveTargetModel(
                entityType: 'reminder',
                oldTable: 'reminders',
                oldId: (int) $row['id'],
                modelClass: Reminder::class,
                naturalResolver: fn (): ?Model => Reminder::query()
                    ->where('title', $row['title'])
                    ->where('recipient_email', $row['recipient_email'])
                    ->where('frequency', 'weekly')
                    ->first(),
            );

            if ($created) {
                $item['created']++;
            } else {
                $item['updated']++;
            }

            if (! $dryRun) {
                $reminder->forceFill([
                    'title' => $this->safeString($row['title'] ?? null),
                    'recipient_email' => $this->safeString($row['recipient_email'] ?? null),
                    'subject' => $this->safeString($row['subject'] ?? null),
                    'body' => $this->safeString($row['body'] ?? null),
                    'frequency' => 'weekly',
                    'day_of_month' => $row['day_of_month'] ?? null,
                    'day_of_week' => $row['day_of_week'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                    'notes' => $this->nullableString($row['notes'] ?? null),
                    'last_sent_at' => $row['last_sent_at'] ?? null,
                ])->save();

                $this->storeMapping('reminder', 'reminders', (int) $row['id'], $reminder->getTable(), (int) $reminder->getKey(), $row);
            }
        }

        return $item;
    }

    protected function simulateSpecializations(ConnectionInterface $sourceConnection): array
    {
        $count = $this->sourceCount($sourceConnection, 'service_categories');
        $item = $this->baseItem('specializations', $count);
        $item['created'] = $count * 2;

        return $item;
    }

    protected function simulateProfessionals(ConnectionInterface $sourceConnection): array
    {
        $professionals = $this->sourceCount($sourceConnection, 'professionals');
        $relations = $this->sourceCount($sourceConnection, 'professional_service_categories');
        $item = $this->baseItem('professionals', $professionals + $relations);
        $item['created'] = $professionals + ($relations * 2);

        return $item;
    }

    protected function simulatePatients(ConnectionInterface $sourceConnection): array
    {
        $count = $this->sourceCount($sourceConnection, 'patients');
        $item = $this->baseItem('patients', $count);
        $item['created'] = $count;

        return $item;
    }

    protected function simulateServices(ConnectionInterface $sourceConnection): array
    {
        $services = $this->sourceCount($sourceConnection, 'services');
        $aliases = $this->sourceCount($sourceConnection, 'service_aliases');
        $professionalServices = $this->sourceCount($sourceConnection, 'professional_services');
        $item = $this->baseItem('services', $services + $aliases + $professionalServices);
        $item['created'] = $services + $aliases + $professionalServices + $this->servicesWithCategoryCount($sourceConnection);

        return $item;
    }

    protected function simulatePerformanceRecords(ConnectionInterface $sourceConnection): array
    {
        $records = $this->sourceCount($sourceConnection, 'performance_records');
        $pivot = $this->sourceCount($sourceConnection, 'patient_performance_record');
        $splits = $this->sourceCount($sourceConnection, 'performance_record_splits');
        $item = $this->baseItem('performance-records', $records + $pivot + $splits);
        $item['created'] = $records + $pivot + $splits;

        return $item;
    }

    protected function simulateExpenses(ConnectionInterface $sourceConnection): array
    {
        $categories = $this->sourceCount($sourceConnection, 'expense_categories');
        $templates = $this->sourceCount($sourceConnection, 'expense_templates');
        $records = $this->sourceCount($sourceConnection, 'expense_records');
        $competences = $this->sourceCount($sourceConnection, 'expense_record_competences');
        $cash = $this->sourceCount($sourceConnection, 'cash_movements');
        $item = $this->baseItem('expenses', $categories + $templates + $records + $competences + $cash);
        $item['created'] = $categories + $templates + $records + $competences + $cash;

        return $item;
    }

    protected function simulateMarketing(ConnectionInterface $sourceConnection): array
    {
        $segments = $this->sourceCount($sourceConnection, 'marketing_segments');
        $recipients = $this->sourceCount($sourceConnection, 'marketing_segment_manual_recipients');
        $campaigns = $this->sourceCount($sourceConnection, 'marketing_campaigns');
        $deliveries = $this->sourceCount($sourceConnection, 'marketing_campaign_deliveries');
        $item = $this->baseItem('marketing', $segments + $recipients + $campaigns + $deliveries);
        $item['created'] = $segments + $recipients + $campaigns + $deliveries;

        return $item;
    }

    protected function simulateSettings(ConnectionInterface $sourceConnection): array
    {
        $settings = min(1, $this->sourceCount($sourceConnection, 'application_settings'));
        $weeklyReminders = 0;

        foreach ($this->fetchRows($sourceConnection, 'reminders') as $row) {
            if (($row['frequency'] ?? null) === 'weekly') {
                $weeklyReminders++;
            }
        }

        $item = $this->baseItem('settings', $this->sourceCount($sourceConnection, 'application_settings') + $this->sourceCount($sourceConnection, 'reminders'));
        $item['created'] = $settings + $weeklyReminders;
        $item['skipped'] = $this->sourceCount($sourceConnection, 'reminders') - $weeklyReminders;

        if ($item['skipped'] > 0) {
            $item['warnings'][] = 'Nel rebuild verranno importati solo i promemoria weekly; quelli con altra frequenza verranno saltati.';
        }

        return $item;
    }

    protected function servicesWithCategoryCount(ConnectionInterface $sourceConnection): int
    {
        if (! $sourceConnection->getSchemaBuilder()->hasTable('services')) {
            return 0;
        }

        return $sourceConnection->table('services')
            ->whereNotNull('category_id')
            ->count();
    }

    /**
     * @return list<string>
     */
    protected function existingRebuildTables(): array
    {
        $schema = DB::getSchemaBuilder();

        return array_values(array_filter(
            self::REBUILD_CLEAR_ORDER,
            static fn (string $table): bool => $schema->hasTable($table),
        ));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string,int>
     */
    protected function targetRowCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param  list<string>  $tables
     */
    protected function clearTargetTables(array $tables): void
    {
        $driver = DB::getDriverName();

        try {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            }

            foreach ($tables as $table) {
                DB::table($table)->delete();

                if ($driver === 'sqlite') {
                    DB::statement("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
                }
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    protected function sourceCount(ConnectionInterface $sourceConnection, string $table): int
    {
        if (! $sourceConnection->getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        return (int) $sourceConnection->table($table)->count();
    }

    /**
     * @return list<array<string,mixed>>
     */
    protected function fetchRows(ConnectionInterface $sourceConnection, string $table): array
    {
        if (! $sourceConnection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $query = $sourceConnection->table($table);

        if ($sourceConnection->getSchemaBuilder()->hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        return $query->get()->map(fn (object $row): array => (array) $row)->all();
    }

    /**
     * @return array{0:Model,1:bool}
     */
    protected function resolveTargetModel(
        string $entityType,
        string $oldTable,
        int $oldId,
        string $modelClass,
        callable $naturalResolver,
    ): array {
        $mapping = OldCoreImportMapping::query()
            ->where('entity_type', $entityType)
            ->where('old_table', $oldTable)
            ->where('old_id', $oldId)
            ->first();

        if ($mapping !== null) {
            $mapped = $modelClass::query()->find($mapping->new_id);

            if ($mapped instanceof Model) {
                return [$mapped, false];
            }
        }

        if ($this->disableNaturalMatching) {
            return [new $modelClass, true];
        }

        $resolved = $naturalResolver();

        if ($resolved instanceof Model) {
            return [$resolved, false];
        }

        return [new $modelClass, true];
    }

    /**
     * @return array{0:Patient,1:bool,2:bool}
     */
    protected function resolvePatient(array $row): array
    {
        $mapping = OldCoreImportMapping::query()
            ->where('entity_type', 'patient')
            ->where('old_table', 'patients')
            ->where('old_id', (int) $row['id'])
            ->first();

        if ($mapping !== null) {
            $mapped = Patient::query()->find($mapping->new_id);

            if ($mapped instanceof Patient) {
                return [$mapped, false, false];
            }
        }

        if ($this->disableNaturalMatching) {
            return [new Patient, true, false];
        }

        $taxCode = $this->normalizeTaxCode($row['tax_code'] ?? null);
        if ($taxCode !== null) {
            $matches = Patient::query()->whereRaw('UPPER(TRIM(tax_code)) = ?', [$taxCode])->get();

            if ($matches->count() === 1) {
                return [$matches->first(), false, false];
            }

            if ($matches->count() > 1) {
                return [new Patient, false, true];
            }
        }

        $email = $this->normalizeEmail($row['email'] ?? null);
        if ($email !== null) {
            $matches = Patient::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->get();

            if ($matches->count() === 1) {
                return [$matches->first(), false, false];
            }

            if ($matches->count() > 1) {
                return [new Patient, false, true];
            }
        }

        $phone = $this->normalizePhone($row['phone'] ?? null);
        $fullName = $this->normalizeText($row['full_name'] ?? null);
        if ($phone !== null && $fullName !== null) {
            $matches = Patient::query()
                ->whereRaw('LOWER(TRIM(full_name)) = ?', [$fullName])
                ->get()
                ->filter(fn (Patient $patient): bool => $this->normalizePhone($patient->phone) === $phone)
                ->values();

            if ($matches->count() === 1) {
                return [$matches->first(), false, false];
            }

            if ($matches->count() > 1) {
                return [new Patient, false, true];
            }
        }

        $birthDate = $row['birth_date'] ?? null;
        if ($fullName !== null && $birthDate !== null) {
            $matches = Patient::query()
                ->whereRaw('LOWER(TRIM(full_name)) = ?', [$fullName])
                ->whereDate('birth_date', $birthDate)
                ->get();

            if ($matches->count() === 1) {
                return [$matches->first(), false, false];
            }

            if ($matches->count() > 1) {
                return [new Patient, false, true];
            }
        }

        return [new Patient, true, false];
    }

    protected function matchProfessional(array $row): ?Professional
    {
        $subjectType = $row['subject_type'] ?? ProfessionalSubjectType::Individual->value;
        $email = $this->normalizeEmail($row['email'] ?? null);
        $iban = $this->normalizeIban($row['iban'] ?? null);
        $fullName = $this->normalizeText($row['full_name'] ?? null);

        if ($email !== null) {
            $matches = Professional::query()
                ->where('subject_type', $subjectType)
                ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        if ($iban !== null) {
            $matches = Professional::query()
                ->where('subject_type', $subjectType)
                ->whereRaw("UPPER(REPLACE(iban, ' ', '')) = ?", [$iban])
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        if ($fullName !== null) {
            $matches = Professional::query()
                ->where('subject_type', $subjectType)
                ->whereRaw('LOWER(TRIM(full_name)) = ?', [$fullName])
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    protected function mappedTargetId(string $entityType, string $oldTable, int $oldId): ?int
    {
        return OldCoreImportMapping::query()
            ->where('entity_type', $entityType)
            ->where('old_table', $oldTable)
            ->where('old_id', $oldId)
            ->value('new_id');
    }

    protected function storeMapping(string $entityType, string $oldTable, int $oldId, string $newTable, int $newId, array $row): void
    {
        OldCoreImportMapping::query()->updateOrCreate(
            [
                'entity_type' => $entityType,
                'old_table' => $oldTable,
                'old_id' => $oldId,
            ],
            [
                'new_table' => $newTable,
                'new_id' => $newId,
                'source_hash' => $this->sourceHash($row),
                'source_updated_at' => $row['updated_at'] ?? null,
            ],
        );
    }

    protected function sourceHash(array $row): string
    {
        ksort($row);

        return sha1((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @return array<string,mixed>
     */
    protected function baseItem(string $group, int $sourceRecords): array
    {
        return [
            'group' => $group,
            'source_records' => $sourceRecords,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'warnings' => [],
            'errors' => [],
            'unmappable' => [],
            'target_tables' => self::GROUP_TARGET_TABLES[$group] ?? [],
            'source_tables' => self::GROUP_SOURCE_TABLES[$group] ?? [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function emptyItem(string $group): array
    {
        return $this->baseItem($group, 0);
    }

    protected function normalizeSlug(mixed $value, mixed $fallback = null): string
    {
        $candidate = trim((string) ($value ?? ''));

        if ($candidate === '') {
            $candidate = trim((string) ($fallback ?? ''));
        }

        $slug = Str::slug($candidate);

        return $slug !== '' ? $slug : 'senza-slug';
    }

    protected function safeString(mixed $value): string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : 'N/D';
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }

    protected function normalizeText(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : mb_strtolower($string);
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : mb_strtolower($string);
    }

    protected function normalizeTaxCode(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : mb_strtoupper($string);
    }

    protected function normalizeIban(mixed $value): ?string
    {
        $string = $this->nullableString($value);

        return $string === null ? null : mb_strtoupper(str_replace(' ', '', $string));
    }

    protected function normalizePhone(mixed $value): ?string
    {
        $string = preg_replace('/\D+/', '', (string) ($value ?? ''));

        return $string === '' ? null : $string;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function decodeJsonToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}
