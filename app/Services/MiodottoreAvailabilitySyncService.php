<?php

namespace App\Services;

use App\Models\ExternalProviderAccount;
use App\Models\ExternalProviderProfessional;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityException;
use App\Models\ProfessionalAvailabilityRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MiodottoreAvailabilitySyncService
{
    public const PROVIDER = 'miodottore';

    public function __construct(
        private readonly MiodottoreAccessService $miodottoreAccessService,
    ) {
    }

    public function snapshot(Professional $professional): array
    {
        $providerProfile = $this->providerProfile($professional);

        return [
            'source' => self::PROVIDER,
            'source_label' => 'MioDottore',
            'provider_profile' => [
                'provider' => self::PROVIDER,
                'external_name' => $providerProfile?->external_name,
                'external_id' => $providerProfile?->external_id,
                'external_url' => $providerProfile?->external_url,
                'enabled' => (bool) ($providerProfile?->enabled ?? false),
                'is_configured' => filled($providerProfile?->external_url),
            ],
            'sync_status' => $providerProfile?->sync_status ?? 'never_synced',
            'sync_status_label' => $this->syncStatusLabel($providerProfile?->sync_status),
            'last_synced_at' => optional($providerProfile?->last_synced_at)->toIso8601String(),
            'last_sync_error' => $providerProfile?->last_sync_error,
            'rules' => ProfessionalAvailabilityRule::query()
                ->where('professional_id', $professional->id)
                ->where('source', self::PROVIDER)
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get(),
            'exceptions' => ProfessionalAvailabilityException::query()
                ->where('professional_id', $professional->id)
                ->where('source', self::PROVIDER)
                ->orderBy('date')
                ->orderBy('start_time')
                ->get(),
        ];
    }

    public function requestSync(Professional $professional): array
    {
        $providerProfile = ExternalProviderProfessional::query()->firstOrCreate(
            [
                'professional_id' => $professional->id,
                'provider' => self::PROVIDER,
            ],
            [
                'external_name' => $professional->full_name,
                'enabled' => true,
                'sync_status' => 'never_synced',
            ],
        );

        if (! filled($providerProfile->external_url)) {
            $this->updateGlobalAccountState(
                loginStatus: 'error',
                lastError: 'URL MioDottore del professionista non configurato.',
            );

            $providerProfile->fill([
                'external_name' => $providerProfile->external_name ?: $professional->full_name,
                'enabled' => true,
                'sync_status' => 'not_configured',
                'last_sync_error' => 'URL MioDottore del professionista non configurato.',
            ])->save();

            return [
                'status' => 'not_configured',
                'message' => 'URL MioDottore del professionista non configurato.',
                'provider_profile' => $providerProfile,
            ];
        }

        if (! filled($providerProfile->external_id)) {
            $this->updateGlobalAccountState(
                loginStatus: 'error',
                lastError: 'Mapping MioDottore del professionista incompleto. Configura l agenda/provider da sincronizzare.',
            );

            $providerProfile->fill([
                'external_name' => $providerProfile->external_name ?: $professional->full_name,
                'enabled' => true,
                'sync_status' => 'error',
                'last_sync_error' => 'Mapping MioDottore del professionista incompleto. Configura l agenda/provider da sincronizzare.',
            ])->save();

            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Mapping MioDottore del professionista incompleto. Configura l agenda/provider da sincronizzare.',
                'provider_profile' => $providerProfile,
                'summary' => [],
            ];
        }

        $access = $this->miodottoreAccessService->verifySavedAccess();
        if (! ($access['success'] ?? false)) {
            $this->updateGlobalAccountState(
                loginStatus: (string) (($access['result']['status'] ?? null) ?: 'session_expired'),
                lastError: 'Sessione MioDottore non valida. Ricollega MioDottore prima di sincronizzare.',
                touchVerifiedAt: true,
            );

            $providerProfile->fill([
                'external_name' => $providerProfile->external_name ?: $professional->full_name,
                'enabled' => true,
                'sync_status' => 'error',
                'last_sync_error' => 'Sessione MioDottore non valida. Ricollega MioDottore prima di sincronizzare.',
            ])->save();

            return [
                'success' => false,
                'status' => (string) (($access['result']['status'] ?? null) ?: 'session_expired'),
                'message' => 'Sessione MioDottore non valida. Ricollega MioDottore prima di sincronizzare.',
                'provider_profile' => $providerProfile,
                'summary' => [],
            ];
        }

        $this->updateGlobalAccountState(
            loginStatus: 'session_valid',
            lastError: null,
            touchVerifiedAt: true,
            touchLoginAt: true,
        );

        $syncResult = $this->syncNormalizedAvailabilities([
            'days' => 30,
            'provider_schedule_ids' => [(string) $providerProfile->external_id],
        ], true);

        $providerProfile->refresh();

        if (! ($syncResult['success'] ?? false)) {
            $this->updateGlobalAccountState(
                loginStatus: 'error',
                lastError: (string) ($syncResult['message'] ?? 'Sincronizzazione disponibilita MioDottore fallita.'),
            );

            $providerProfile->fill([
                'sync_status' => 'error',
                'last_sync_error' => (string) ($syncResult['message'] ?? 'Sincronizzazione disponibilita MioDottore fallita.'),
            ])->save();

            return [
                'success' => false,
                'status' => 'error',
                'message' => (string) ($syncResult['message'] ?? 'Sincronizzazione disponibilita MioDottore fallita.'),
                'provider_profile' => $providerProfile,
                'summary' => [],
            ];
        }

        $plan = is_array($syncResult['plan'] ?? null) ? $syncResult['plan'] : [];
        $dbResult = is_array($syncResult['db_result'] ?? null) ? $syncResult['db_result'] : [];

        return [
            'success' => true,
            'status' => 'completed',
            'message' => 'Disponibilita MioDottore sincronizzate correttamente.',
            'provider_profile' => $providerProfile,
            'summary' => [
                'professionals_matched' => (int) ($plan['mapped_professionals'] ?? 0),
                'weekly_hours_written' => (int) ($dbResult['inserted_rule_rows'] ?? 0),
                'daily_available_exceptions_written' => (int) ($dbResult['inserted_exception_rows'] ?? 0),
                'unavailable_blocks_ignored' => (int) ($plan['ignored_unavailable_blocks'] ?? 0),
                'appointments_ignored_for_availability_split' => (int) (($syncResult['normalized']['summary']['appointments_count'] ?? 0)),
                'old_miodottore_rows_deleted' => (int) ($dbResult['deleted_rows'] ?? 0),
            ],
        ];
    }

    /**
     * @param  array{from?: string|null, to?: string|null, days?: int|null, doctor?: string|null}  $filters
     * @return array{
     *   success: bool,
     *   dry_run: bool,
     *   write: bool,
     *   message: string,
     *   output_dir: string,
     *   access_check: array<string, mixed>,
     *   normalized: array<string, mixed>,
     *   plan: array<string, mixed>,
     *   db_result: array<string, mixed>
     * }
     */
    public function syncNormalizedAvailabilities(array $filters = [], bool $write = false): array
    {
        $relativeOutputDir = $this->newSyncOutputDir();
        $absoluteOutputDir = $this->absoluteArtifactPath($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        $startedAt = now();
        $normalizedSource = $this->miodottoreAccessService->debugMiodottoreAvailabilities($filters);

        $normalized = is_array($normalizedSource['normalized'] ?? null) ? $normalizedSource['normalized'] : [];
        $from = (string) ($normalized['from'] ?? $filters['from'] ?? '');
        $to = (string) ($normalized['to'] ?? $filters['to'] ?? '');
        $requestedScheduleIds = collect($filters['provider_schedule_ids'] ?? [])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();

        $this->writeJson($absoluteOutputDir, '00-start.json', [
            'provider' => self::PROVIDER,
            'started_at' => $startedAt->toIso8601String(),
            'dry_run' => ! $write,
            'write' => $write,
            'filters' => $filters,
        ]);
        $this->writeJson($absoluteOutputDir, '01-access-check.json', $normalizedSource['access_check']['result'] ?? $normalizedSource['access_check'] ?? []);
        $this->writeJson($absoluteOutputDir, '02-normalized-source.json', $normalized);

        if (! ($normalizedSource['success'] ?? false)) {
            $result = [
                'success' => false,
                'dry_run' => ! $write,
                'write' => $write,
                'message' => (string) ($normalizedSource['message'] ?? 'Sessione MioDottore non valida.'),
                'output_dir' => $relativeOutputDir,
                'access_check' => $normalizedSource['access_check'] ?? [],
                'normalized' => $normalized,
                'plan' => [],
                'db_result' => [],
            ];

            $this->writeJson($absoluteOutputDir, '03-sync-plan.json', []);
            $this->writeJson($absoluteOutputDir, '04-db-result.json', []);
            $this->writeJson($absoluteOutputDir, 'result.json', $result);

            return $result;
        }

        $professionals = is_array($normalized['professionals'] ?? null) ? $normalized['professionals'] : [];
        if ($requestedScheduleIds !== []) {
            $professionals = array_values(array_filter($professionals, static function ($professional) use ($requestedScheduleIds): bool {
                if (! is_array($professional) || ! isset($professional['provider_schedule_id'])) {
                    return false;
                }

                return in_array((string) $professional['provider_schedule_id'], $requestedScheduleIds, true);
            }));
            $normalized['professionals'] = $professionals;
            $normalized['summary']['schedules_count'] = count($professionals);
            $normalized['summary']['weekly_hours_count'] = collect($professionals)->sum(fn ($professional) => count($professional['weekly_hours'] ?? []));
            $normalized['summary']['daily_available_exceptions_count'] = collect($professionals)->sum(fn ($professional) => count($professional['daily_available_exceptions'] ?? []));
            $normalized['summary']['ignored_unavailable_blocks_count'] = collect($professionals)->sum(fn ($professional) => count($professional['ignored_unavailable_blocks'] ?? []));
            $normalized['summary']['normalized_days_count'] = collect($professionals)->sum(fn ($professional) => count($professional['days'] ?? []));
            $normalized['summary']['appointments_count'] = collect($professionals)->sum(fn ($professional) => count($professional['appointments'] ?? []));
        }
        $scheduleIds = collect($professionals)
            ->pluck('provider_schedule_id')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        $providerProfiles = ExternalProviderProfessional::query()
            ->where('provider', self::PROVIDER)
            ->where('enabled', true)
            ->whereIn('external_id', $scheduleIds)
            ->get()
            ->keyBy(fn (ExternalProviderProfessional $profile) => (string) $profile->external_id);

        $mappedProfessionals = [];
        $unmappedProfessionals = [];
        $ruleRows = [];
        $availableExceptionRows = [];
        $ignoredUnavailableBlocks = [];
        $targetProfessionalIds = [];
        $syncedAt = now();

        foreach ($professionals as $professional) {
            if (! is_array($professional)) {
                continue;
            }

            $scheduleId = isset($professional['provider_schedule_id']) ? (string) $professional['provider_schedule_id'] : null;
            if ($scheduleId === null || $scheduleId === '') {
                $unmappedProfessionals[] = [
                    'provider_schedule_id' => null,
                    'provider_name' => $professional['display_name'] ?? $professional['provider_name'] ?? 'Schedule senza id',
                    'reason' => 'provider_schedule_id mancante nel normalized.',
                ];
                continue;
            }

            /** @var ExternalProviderProfessional|null $mapping */
            $mapping = $providerProfiles->get($scheduleId);

            if ($mapping === null) {
                $unmappedProfessionals[] = [
                    'provider_schedule_id' => (int) $scheduleId,
                    'provider_name' => $professional['display_name'] ?? $professional['provider_name'] ?? 'Professionista non mappato',
                    'reason' => 'Nessun mapping abilitato in external_provider_professionals.',
                ];
                continue;
            }

            $targetProfessionalIds[] = $mapping->professional_id;
            $mappedProfessionals[] = [
                'provider_schedule_id' => (int) $scheduleId,
                'professional_id' => $mapping->professional_id,
                'provider_name' => $professional['display_name'] ?? $professional['provider_name'] ?? $mapping->external_name,
                'external_name' => $mapping->external_name,
            ];

            foreach (($professional['weekly_hours'] ?? $professional['orari_settimanali'] ?? []) as $weeklyHour) {
                if (! is_array($weeklyHour)) {
                    continue;
                }

                $row = $this->buildRuleRow(
                    professionalId: $mapping->professional_id,
                    providerScheduleId: (int) $scheduleId,
                    weeklyHour: $weeklyHour,
                    syncedAt: $syncedAt,
                );

                if ($row !== null) {
                    $ruleRows[] = $row;
                }
            }

            foreach (($professional['daily_available_exceptions'] ?? $professional['eccezioni_disponibilita'] ?? []) as $exception) {
                if (! is_array($exception)) {
                    continue;
                }

                $row = $this->buildAvailableExceptionRow(
                    professionalId: $mapping->professional_id,
                    providerScheduleId: (int) $scheduleId,
                    exception: $exception,
                    syncedAt: $syncedAt,
                );

                if ($row !== null) {
                    $availableExceptionRows[] = $row;
                }
            }

            foreach (($professional['ignored_unavailable_blocks'] ?? $professional['blocchi_non_disponibilita_ignorati'] ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $ignoredUnavailableBlocks[] = [
                    'professional_id' => $mapping->professional_id,
                    'provider_schedule_id' => (int) $scheduleId,
                    ...$block,
                ];
            }
        }

        $targetProfessionalIds = array_values(array_unique($targetProfessionalIds));
        $deleteExceptionRowsCount = 0;
        $deleteRuleRowsCount = 0;
        $deleteExceptionsQuery = null;
        $deleteRulesQuery = null;

        if ($targetProfessionalIds !== []) {
            $deleteExceptionsQuery = ProfessionalAvailabilityException::query()
                ->where('source', self::PROVIDER)
                ->whereIn('professional_id', $targetProfessionalIds)
                ->whereBetween('date', [$from, $to]);

            $deleteRulesQuery = ProfessionalAvailabilityRule::query()
                ->where('source', self::PROVIDER)
                ->whereIn('professional_id', $targetProfessionalIds);

            $deleteExceptionRowsCount = (clone $deleteExceptionsQuery)->count();
            $deleteRuleRowsCount = (clone $deleteRulesQuery)->count();
        }

        $plan = [
            'dry_run' => ! $write,
            'write' => $write,
            'from' => $from,
            'to' => $to,
            'mapped_professionals' => count($mappedProfessionals),
            'mapped_professionals_list' => $mappedProfessionals,
            'unmapped_professionals' => $unmappedProfessionals,
            'weekly_rule_rows' => count($ruleRows),
            'daily_available_exception_rows' => count($availableExceptionRows),
            'ignored_unavailable_blocks' => count($ignoredUnavailableBlocks),
            'delete_existing_miodottore_rules' => $deleteRuleRowsCount,
            'delete_existing_miodottore_exceptions_in_range' => $deleteExceptionRowsCount,
            'rows_preview' => [
                'weekly_rules' => array_slice($ruleRows, 0, 15),
                'daily_available_exceptions' => array_slice($availableExceptionRows, 0, 15),
                'ignored_unavailable_blocks' => array_slice($ignoredUnavailableBlocks, 0, 15),
            ],
            'available_rows' => count($availableExceptionRows),
            'unavailable_rows' => 0,
            'delete_existing_miodottore_rows_in_range' => $deleteExceptionRowsCount,
        ];

        $dbResult = [
            'written' => false,
            'deleted_rule_rows' => 0,
            'deleted_exception_rows' => 0,
            'deleted_rows' => 0,
            'inserted_rule_rows' => 0,
            'inserted_exception_rows' => 0,
            'inserted_rows' => 0,
            'preserved_manual_rows' => true,
        ];

        if ($write) {
            DB::transaction(function () use (
                $deleteExceptionsQuery,
                $deleteRulesQuery,
                $availableExceptionRows,
                $ruleRows,
                $mappedProfessionals,
                $syncedAt,
                &$dbResult,
            ): void {
                $deletedExceptionRows = $deleteExceptionsQuery ? (clone $deleteExceptionsQuery)->delete() : 0;
                $deletedRuleRows = $deleteRulesQuery ? (clone $deleteRulesQuery)->delete() : 0;

                if ($ruleRows !== []) {
                    foreach (array_chunk($ruleRows, 250) as $chunk) {
                        ProfessionalAvailabilityRule::query()->insert($chunk);
                    }
                }

                if ($availableExceptionRows !== []) {
                    foreach (array_chunk($availableExceptionRows, 250) as $chunk) {
                        ProfessionalAvailabilityException::query()->insert($chunk);
                    }
                }

                $mappedProfessionalIds = collect($mappedProfessionals)->pluck('professional_id')->unique()->values()->all();
                if ($mappedProfessionalIds !== []) {
                    ExternalProviderProfessional::query()
                        ->where('provider', self::PROVIDER)
                        ->whereIn('professional_id', $mappedProfessionalIds)
                        ->update([
                            'last_synced_at' => $syncedAt,
                            'sync_status' => 'synced',
                            'last_sync_error' => null,
                            'updated_at' => $syncedAt,
                        ]);
                }

                ExternalProviderAccount::query()->updateOrCreate(
                    ['provider' => self::PROVIDER],
                    [
                        'label' => 'MioDottore',
                        'enabled' => true,
                        'last_availability_sync_at' => $syncedAt,
                        'last_error' => null,
                    ],
                );

                $dbResult = [
                    'written' => true,
                    'deleted_rule_rows' => $deletedRuleRows,
                    'deleted_exception_rows' => $deletedExceptionRows,
                    'deleted_rows' => $deletedRuleRows + $deletedExceptionRows,
                    'inserted_rule_rows' => count($ruleRows),
                    'inserted_exception_rows' => count($availableExceptionRows),
                    'inserted_rows' => count($ruleRows) + count($availableExceptionRows),
                    'preserved_manual_rows' => true,
                ];
            });
        }

        $result = [
            'success' => true,
            'dry_run' => ! $write,
            'write' => $write,
            'message' => $write
                ? 'Sync disponibilita MioDottore completata.'
                : 'Dry-run completato. Nessuna scrittura eseguita.',
            'output_dir' => $relativeOutputDir,
            'access_check' => $normalizedSource['access_check'] ?? [],
            'normalized' => $normalized,
            'plan' => $plan,
            'db_result' => $dbResult,
        ];

        $this->writeJson($absoluteOutputDir, '03-sync-plan.json', $plan);
        $this->writeJson($absoluteOutputDir, '04-db-result.json', $dbResult);
        $this->writeJson($absoluteOutputDir, 'result.json', $result);

        return $result;
    }

    public function syncStatusLabel(?string $status): string
    {
        return match ($status) {
            'synced' => 'Sincronizzato',
            'not_configured' => 'Non configurata',
            'error' => 'Errore',
            default => 'Mai sincronizzato',
        };
    }

    public function updateProviderProfile(Professional $professional, string $externalUrl): ExternalProviderProfessional
    {
        $providerProfile = ExternalProviderProfessional::query()->updateOrCreate(
            [
                'professional_id' => $professional->id,
                'provider' => self::PROVIDER,
            ],
            [
                'external_name' => $professional->full_name,
                'external_url' => $externalUrl,
                'enabled' => true,
                'sync_status' => 'never_synced',
                'last_sync_error' => null,
            ],
        );

        if (! $providerProfile->sync_status) {
            $providerProfile->forceFill(['sync_status' => 'never_synced'])->save();
        }

        return $providerProfile;
    }

    private function providerProfile(Professional $professional): ?ExternalProviderProfessional
    {
        return ExternalProviderProfessional::query()
            ->where('professional_id', $professional->id)
            ->where('provider', self::PROVIDER)
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAvailableExceptionRow(
        int $professionalId,
        int $providerScheduleId,
        array $exception,
        \Illuminate\Support\Carbon $syncedAt,
    ): ?array
    {
        $date = is_string($exception['date'] ?? null) ? $exception['date'] : null;
        $start = is_string($exception['start'] ?? null) ? $exception['start'] : null;
        $end = is_string($exception['end'] ?? null) ? $exception['end'] : null;

        if (! filled($date) || ! filled($start) || ! filled($end)) {
            return null;
        }

        return [
            'professional_id' => $professionalId,
            'source' => self::PROVIDER,
            'date' => $date,
            'type' => 'available',
            'start_time' => $start,
            'end_time' => $end,
            'reason' => null,
            'external_hash' => sha1(sprintf(
                'miodottore|available|%s|%s|%s|%s|%s',
                $providerScheduleId,
                $professionalId,
                $date,
                $start,
                $end,
            )),
            'last_synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $weeklyHour
     * @return array<string, mixed>|null
     */
    private function buildRuleRow(
        int $professionalId,
        int $providerScheduleId,
        array $weeklyHour,
        \Illuminate\Support\Carbon $syncedAt,
    ): ?array
    {
        $weekday = $this->resolveWeekdayIso($weeklyHour['weekday'] ?? null);
        $start = is_string($weeklyHour['start'] ?? null) ? $weeklyHour['start'] : null;
        $end = is_string($weeklyHour['end'] ?? null) ? $weeklyHour['end'] : null;

        if ($weekday === null || ! filled($start) || ! filled($end)) {
            return null;
        }

        return [
            'professional_id' => $professionalId,
            'source' => self::PROVIDER,
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
            'notes' => null,
            'external_hash' => sha1(sprintf(
                'miodottore|rule|%s|%s|%s|%s|%s',
                $providerScheduleId,
                $professionalId,
                $weekday,
                $start,
                $end,
            )),
            'last_synced_at' => $syncedAt,
            'created_at' => $syncedAt,
            'updated_at' => $syncedAt,
        ];
    }

    private function resolveWeekdayIso(mixed $weekday): ?int
    {
        if (is_int($weekday) && $weekday >= 1 && $weekday <= 7) {
            return $weekday;
        }

        if (is_numeric($weekday)) {
            $numericWeekday = (int) $weekday;

            return $numericWeekday >= 1 && $numericWeekday <= 7 ? $numericWeekday : null;
        }

        if (is_string($weekday) && $weekday !== '') {
            $normalized = Str::lower(trim($weekday));

            return match ($normalized) {
                'monday', 'mondays', 'lunedi', 'lunedì' => 1,
                'tuesday', 'tuesdays', 'martedi', 'martedì' => 2,
                'wednesday', 'wednesdays', 'mercoledi', 'mercoledì' => 3,
                'thursday', 'thursdays', 'giovedi', 'giovedì' => 4,
                'friday', 'fridays', 'venerdi', 'venerdì' => 5,
                'saturday', 'saturdays', 'sabato' => 6,
                'sunday', 'sundays', 'domenica' => 7,
                default => null,
            };
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $absoluteOutputDir, string $fileName, array $payload): void
    {
        File::put(
            $absoluteOutputDir.DIRECTORY_SEPARATOR.$fileName,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function newSyncOutputDir(): string
    {
        return sprintf(
            'private/miodottore-access/sync-availabilities/%s_%s',
            now()->format('Ymd_His'),
            Str::lower(Str::random(4)),
        );
    }

    private function absoluteArtifactPath(string $outputDir): string
    {
        $diskRelativePath = preg_replace('#^private/#', '', $outputDir) ?? $outputDir;

        return Storage::disk('local')->path($diskRelativePath);
    }

    private function updateGlobalAccountState(
        string $loginStatus,
        ?string $lastError,
        bool $touchVerifiedAt = false,
        bool $touchLoginAt = false,
    ): void {
        $payload = [
            'label' => 'MioDottore',
            'enabled' => true,
            'login_status' => $loginStatus,
            'last_error' => $lastError,
        ];

        if ($touchVerifiedAt) {
            $payload['last_session_verified_at'] = now();
        }

        if ($touchLoginAt) {
            $payload['last_login_at'] = now();
        }

        ExternalProviderAccount::query()->updateOrCreate(
            ['provider' => self::PROVIDER],
            $payload,
        );
    }
}
