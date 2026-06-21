<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class MiodottoreAccessService
{
    private const DEFAULT_STORAGE_STATE_PATH = 'miodottore/storage-state.json';

    /**
     * @return array{success: bool, message: string, output_dir: string, state_path: string, result: array<string, mixed>}
     */
    public function runBackgroundLogin(string $username, string $password, ?string $relativeOutputDir = null): array
    {
        $loginUrl = $this->loginUrl();
        if ($loginUrl === '') {
            throw new RuntimeException('Configura MIODOTTORE_LOGIN_URL prima di avviare il collegamento MioDottore.');
        }

        $statePath = $this->absoluteStorageStatePath();
        File::ensureDirectoryExists(dirname($statePath));

        $relativeOutputDir ??= $this->newBackgroundLoginOutputDir();
        $absoluteOutputDir = Storage::disk('local')->path($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        $args = [
            'node',
            base_path('scripts/miodottore/background-login.mjs'),
            '--login-url', $loginUrl,
            '--verify-url', $this->verifyUrl(),
            '--state-path', $statePath,
            '--output-dir', $absoluteOutputDir,
            '--headless', 'true',
            '--timeout-ms', (string) $this->timeoutMs(),
        ];

        $chromiumPath = $this->chromiumPath();
        if ($chromiumPath !== '') {
            $args[] = '--chromium-path';
            $args[] = $chromiumPath;
        }

        $baseEnvironment = array_filter(
            array_merge($_ENV, $_SERVER),
            static fn (mixed $value): bool => is_scalar($value) || $value === null
        );

        $processEnvironment = array_merge($baseEnvironment, [
            'MIODOTTORE_BG_USERNAME' => $username,
            'MIODOTTORE_BG_PASSWORD' => $password,
        ]);

        $process = new Process($args, base_path(), $processEnvironment, null, max(300, (int) ceil($this->timeoutMs() / 1000) + 60));
        $process->setTimeout(max(600, (int) ceil($this->timeoutMs() / 1000) + 120));
        $process->setIdleTimeout(null);

        $exitCode = $process->run();
        $result = $this->readResultFile($absoluteOutputDir);
        $success = (bool) ($result['success'] ?? ($exitCode === 0));

        return [
            'success' => $success,
            'message' => $result['message'] ?? ($success
                ? 'Accesso MioDottore completato e sessione salvata.'
                : 'Accesso MioDottore non completato. Controlla gli artefatti di debug.'),
            'output_dir' => $relativeOutputDir,
            'state_path' => $this->storageStateRelativePath(),
            'result' => $result,
        ];
    }

    /**
     * @return array{success: bool, message: string, output_dir: string, state_path: string, result: array<string, mixed>}
     */
    public function runInteractiveLogin(?string $relativeOutputDir = null): array
    {
        $loginUrl = $this->loginUrl();
        if ($loginUrl === '') {
            throw new RuntimeException('Configura MIODOTTORE_LOGIN_URL prima di avviare il ricollegamento assistito MioDottore.');
        }

        $statePath = $this->absoluteStorageStatePath();
        File::ensureDirectoryExists(dirname($statePath));

        $relativeOutputDir ??= $this->newLoginOutputDir();
        $absoluteOutputDir = Storage::disk('local')->path($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        $args = [
            'node',
            base_path('scripts/miodottore/login.mjs'),
            '--login-url', $loginUrl,
            '--verify-url', $this->verifyUrl(),
            '--state-path', $statePath,
            '--output-dir', $absoluteOutputDir,
            '--headless', 'false',
            '--timeout-ms', (string) $this->timeoutMs(),
            '--slowmo-ms', (string) $this->slowMoMs(),
        ];

        $chromiumPath = $this->chromiumPath();
        if ($chromiumPath !== '') {
            $args[] = '--chromium-path';
            $args[] = $chromiumPath;
        }

        $process = new Process(
            $args,
            base_path(),
            null,
            null,
            max(600, (int) ceil($this->timeoutMs() / 1000) + 120)
        );
        $process->setTimeout(max(900, (int) ceil($this->timeoutMs() / 1000) + 180));
        $process->setIdleTimeout(null);

        $exitCode = $process->run();
        $result = $this->readResultFile($absoluteOutputDir);
        $success = (bool) ($result['success'] ?? ($exitCode === 0));

        return [
            'success' => $success,
            'message' => $result['message'] ?? ($success
                ? 'Accesso MioDottore completato e sessione salvata.'
                : 'Accesso MioDottore non completato. Controlla gli artefatti di debug.'),
            'output_dir' => $relativeOutputDir,
            'state_path' => $this->storageStateRelativePath(),
            'result' => $result,
        ];
    }

    /**
     * @return array{success: bool, message: string, output_dir: string, state_path: string, result: array<string, mixed>}
     */
    public function verifySavedAccess(): array
    {
        $loginUrl = $this->loginUrl();
        $verifyUrl = $this->verifyUrl();
        $absoluteStatePath = $this->resolveExistingStorageStateAbsolutePath();

        if ($loginUrl === '') {
            throw new RuntimeException('Configura MIODOTTORE_LOGIN_URL prima di verificare l accesso MioDottore.');
        }

        if ($absoluteStatePath === null || ! File::exists($absoluteStatePath)) {
            return [
                'success' => false,
                'message' => 'Nessuna sessione MioDottore salvata. Completa prima il collegamento.',
                'output_dir' => '',
                'state_path' => $this->storageStateRelativePath(),
                'result' => [],
            ];
        }

        $relativeOutputDir = $this->newTimestampedDir('miodottore-access/verify');
        $absoluteOutputDir = Storage::disk('local')->path($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        $args = [
            'node',
            base_path('scripts/miodottore/verify-access.mjs'),
            '--login-url', $loginUrl,
            '--verify-url', $verifyUrl,
            '--state-path', $absoluteStatePath,
            '--output-dir', $absoluteOutputDir,
            '--headless', 'true',
            '--timeout-ms', (string) min($this->timeoutMs(), 120000),
            '--slowmo-ms', '0',
        ];

        $chromiumPath = $this->chromiumPath();
        if ($chromiumPath !== '') {
            $args[] = '--chromium-path';
            $args[] = $chromiumPath;
        }

        $process = new Process($args, base_path(), null, null, 180);
        $process->setTimeout(180);
        $process->setIdleTimeout(180);

        $exitCode = $process->run();
        $result = $this->readResultFile($absoluteOutputDir);

        return [
            'success' => (bool) ($result['success'] ?? ($exitCode === 0)),
            'message' => $result['message'] ?? ($exitCode === 0
                ? 'Accesso MioDottore verificato correttamente.'
                : 'Sessione MioDottore non valida o scaduta.'),
            'output_dir' => $relativeOutputDir,
            'state_path' => $this->storageStateRelativePath(),
            'result' => $result,
        ];
    }

    public function storageStateRelativePath(): string
    {
        return $this->normalizeStorageStateRelativePath(
            (string) (config('services.miodottore.storage_state_path') ?: self::DEFAULT_STORAGE_STATE_PATH)
        );
    }

    public function absoluteStorageStatePath(): string
    {
        return Storage::disk('local')->path($this->storageStateRelativePath());
    }

    public function loginUrl(): string
    {
        return trim((string) config('services.miodottore.login_url'));
    }

    public function verifyUrl(): string
    {
        return trim((string) (config('services.miodottore.verify_url') ?: 'https://docplanner.miodottore.it/#/'));
    }

    public function chromiumPath(): string
    {
        return trim((string) (config('services.miodottore.chromium_path') ?: config('services.miodottore.debug_chromium_path') ?: ''));
    }

    public function timeoutMs(): int
    {
        return max(60000, (int) (config('services.miodottore.access_timeout_ms') ?: 1800000));
    }

    public function slowMoMs(): int
    {
        return max(0, (int) (config('services.miodottore.access_slowmo_ms') ?: 150));
    }

    public function timeoutSeconds(): int
    {
        return (int) ceil($this->timeoutMs() / 1000);
    }

    public function newLoginOutputDir(): string
    {
        return $this->newTimestampedDir('miodottore-access/manual-login');
    }

    public function newBackgroundLoginOutputDir(): string
    {
        return $this->newTimestampedDir('miodottore-access/background-login');
    }

    public function clearSavedAccessState(): void
    {
        $normalizedAbsolutePath = Storage::disk('local')->path($this->storageStateRelativePath());
        $legacyAbsolutePath = Storage::disk('local')->path('private/'.$this->storageStateRelativePath());

        foreach ([$normalizedAbsolutePath, $legacyAbsolutePath] as $candidate) {
            if (File::exists($candidate)) {
                File::delete($candidate);
            }
        }
    }

    /**
     * @param  array{from?: string|null, to?: string|null, days?: int|null, doctor?: string|null}  $filters
     * @return array{
     *   success: bool,
     *   message: string,
     *   output_dir: string,
     *   state_path: string,
     *   access_check: array<string, mixed>,
     *   result: array<string, mixed>,
     *   normalized: array<string, mixed>,
     *   summary: array{
     *     professionals_count: int,
     *     schedules_count: int,
     *     workperiods_count: int,
     *     appointments_count: int,
     *     blocks_count: int,
     *     normalized_days_count: int,
     *     weekly_hours_count: int,
     *     daily_available_exceptions_count: int,
     *     ignored_unavailable_blocks_count: int,
     *     warnings_count: int
     *   }
     * }
     */
    public function debugMiodottoreAvailabilities(array $filters = []): array
    {
        return $this->debugAvailabilities($filters);
    }

    /**
     * @param  array{from?: string|null, to?: string|null, days?: int|null, doctor?: string|null}  $filters
     * @return array{
     *   success: bool,
     *   message: string,
     *   output_dir: string,
     *   state_path: string,
     *   access_check: array<string, mixed>,
     *   result: array<string, mixed>,
     *   normalized: array<string, mixed>,
     *   summary: array{
     *     professionals_count: int,
     *     schedules_count: int,
     *     workperiods_count: int,
     *     appointments_count: int,
     *     blocks_count: int,
     *     normalized_days_count: int,
     *     weekly_hours_count: int,
     *     daily_available_exceptions_count: int,
     *     ignored_unavailable_blocks_count: int,
     *     warnings_count: int
     *   }
     * }
     */
    public function debugAvailabilities(array $filters = []): array
    {
        $loginUrl = $this->loginUrl();
        $verifyUrl = $this->verifyUrl();
        $absoluteStatePath = $this->resolveExistingStorageStateAbsolutePath();

        if ($loginUrl === '') {
            throw new RuntimeException('Configura MIODOTTORE_LOGIN_URL prima di leggere le disponibilita MioDottore.');
        }

        if ($absoluteStatePath === null || ! File::exists($absoluteStatePath)) {
            return [
                'success' => false,
                'message' => 'Sessione MioDottore non valida. Ricollega MioDottore dalla pagina Integrazioni.',
                'output_dir' => '',
                'state_path' => $this->storageStateRelativePath(),
                'access_check' => [],
                'result' => [],
                'normalized' => [],
                'summary' => [
                    'professionals_count' => 0,
                    'schedules_count' => 0,
                    'workperiods_count' => 0,
                    'appointments_count' => 0,
                    'blocks_count' => 0,
                    'normalized_days_count' => 0,
                    'weekly_hours_count' => 0,
                    'daily_available_exceptions_count' => 0,
                    'ignored_unavailable_blocks_count' => 0,
                    'warnings_count' => 0,
                ],
            ];
        }

        $accessCheck = $this->verifySavedAccess();
        if (! ($accessCheck['success'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Sessione MioDottore non valida. Ricollega MioDottore dalla pagina Integrazioni.',
                'output_dir' => (string) ($accessCheck['output_dir'] ?? ''),
                'state_path' => $this->storageStateRelativePath(),
                'access_check' => $accessCheck,
                'result' => [],
                'normalized' => [],
                'summary' => [
                    'professionals_count' => 0,
                    'schedules_count' => 0,
                    'workperiods_count' => 0,
                    'appointments_count' => 0,
                    'blocks_count' => 0,
                    'normalized_days_count' => 0,
                    'weekly_hours_count' => 0,
                    'daily_available_exceptions_count' => 0,
                    'ignored_unavailable_blocks_count' => 0,
                    'warnings_count' => 0,
                ],
            ];
        }

        $resolvedFilters = $this->resolveAvailabilityDebugFilters($filters);
        $relativeOutputDir = $this->newTimestampedDir('miodottore-access/availabilities');
        $absoluteOutputDir = Storage::disk('local')->path($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        File::put(
            $absoluteOutputDir.DIRECTORY_SEPARATOR.'01-access-check.json',
            json_encode($accessCheck['result'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $args = [
            'node',
            base_path('scripts/miodottore/debug-availabilities.mjs'),
            '--login-url', $loginUrl,
            '--verify-url', $verifyUrl,
            '--state-path', $absoluteStatePath,
            '--output-dir', $absoluteOutputDir,
            '--from', $resolvedFilters['from'],
            '--to', $resolvedFilters['to'],
            '--days', (string) $resolvedFilters['days'],
            '--headless', 'true',
            '--timeout-ms', (string) min($this->timeoutMs(), 180000),
        ];

        if ($resolvedFilters['doctor'] !== null && $resolvedFilters['doctor'] !== '') {
            $args[] = '--doctor';
            $args[] = $resolvedFilters['doctor'];
        }

        $chromiumPath = $this->chromiumPath();
        if ($chromiumPath !== '') {
            $args[] = '--chromium-path';
            $args[] = $chromiumPath;
        }

        $process = new Process($args, base_path(), null, null, 300);
        $process->setTimeout(300);
        $process->setIdleTimeout(300);

        $exitCode = $process->run();
        $result = $this->readResultFile($absoluteOutputDir);
        $normalized = $this->readJsonFile($absoluteOutputDir.DIRECTORY_SEPARATOR.'06-availabilities.normalized.json');
        $summary = $this->buildAvailabilityDebugSummary($normalized, $resolvedFilters);

        return [
            'success' => (bool) ($result['success'] ?? ($exitCode === 0)),
            'message' => (string) ($result['message'] ?? ($exitCode === 0
                ? 'Lettura disponibilita MioDottore completata.'
                : 'Lettura disponibilita MioDottore completata con warning. Controlla gli artefatti di debug.')),
            'output_dir' => $relativeOutputDir,
            'state_path' => $this->storageStateRelativePath(),
            'access_check' => $accessCheck,
            'result' => $result,
            'normalized' => $normalized,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readResultFile(string $absoluteOutputDir): array
    {
        $resultPath = $absoluteOutputDir.DIRECTORY_SEPARATOR.'result.json';

        return $this->readJsonFile($resultPath);
    }

    private function normalizeStorageStateRelativePath(string $value): string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($value)), '/');
        $normalized = preg_replace('#^app/private/#', '', $normalized) ?? $normalized;
        $normalized = preg_replace('#^private/#', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : self::DEFAULT_STORAGE_STATE_PATH;
    }

    private function resolveExistingStorageStateAbsolutePath(): ?string
    {
        $normalizedRelativePath = $this->storageStateRelativePath();
        $normalizedAbsolutePath = Storage::disk('local')->path($normalizedRelativePath);

        if (File::exists($normalizedAbsolutePath)) {
            return $normalizedAbsolutePath;
        }

        $legacyRelativePath = 'private/'.$normalizedRelativePath;
        $legacyAbsolutePath = Storage::disk('local')->path($legacyRelativePath);
        if (! File::exists($legacyAbsolutePath)) {
            return $normalizedAbsolutePath;
        }

        File::ensureDirectoryExists(dirname($normalizedAbsolutePath));
        File::copy($legacyAbsolutePath, $normalizedAbsolutePath);

        return $normalizedAbsolutePath;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string, days: int, doctor: string|null}
     */
    private function resolveAvailabilityDebugFilters(array $filters): array
    {
        $today = CarbonImmutable::today();
        $from = isset($filters['from']) && is_string($filters['from']) && $filters['from'] !== ''
            ? CarbonImmutable::parse($filters['from'])->startOfDay()
            : $today;

        if (isset($filters['to']) && is_string($filters['to']) && $filters['to'] !== '') {
            $to = CarbonImmutable::parse($filters['to'])->startOfDay();
        } else {
            $days = max(1, (int) ($filters['days'] ?? 30));
            $to = $from->addDays($days);
        }

        if ($to->lessThan($from)) {
            throw new RuntimeException('L intervallo richiesto non e valido: la data finale e precedente alla data iniziale.');
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => max(1, $from->diffInDays($to) ?: 1),
            'doctor' => isset($filters['doctor']) && is_string($filters['doctor']) && trim($filters['doctor']) !== ''
                ? trim($filters['doctor'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array{from: string, to: string, days: int, doctor: string|null}  $filters
     * @return array{
     *   professionals_count: int,
     *   schedules_count: int,
     *   workperiods_count: int,
     *   appointments_count: int,
     *   blocks_count: int,
     *   normalized_days_count: int,
     *   weekly_hours_count: int,
     *   daily_available_exceptions_count: int,
     *   ignored_unavailable_blocks_count: int,
     *   warnings_count: int
     * }
     */
    private function buildAvailabilityDebugSummary(array $normalized, array $filters): array
    {
        $professionals = is_array($normalized['professionals'] ?? null) ? $normalized['professionals'] : [];
        $warnings = is_array($normalized['warnings'] ?? null) ? $normalized['warnings'] : [];
        $summary = is_array($normalized['summary'] ?? null) ? $normalized['summary'] : [];

        return [
            'professionals_count' => count($professionals),
            'schedules_count' => (int) ($summary['schedules_count'] ?? count($professionals)),
            'workperiods_count' => (int) ($summary['workperiods_count'] ?? 0),
            'appointments_count' => (int) ($summary['appointments_count'] ?? 0),
            'blocks_count' => (int) ($summary['blocks_count'] ?? 0),
            'normalized_days_count' => (int) ($summary['normalized_days_count'] ?? max(1, $filters['days'])),
            'weekly_hours_count' => (int) ($summary['weekly_hours_count'] ?? 0),
            'daily_available_exceptions_count' => (int) ($summary['daily_available_exceptions_count'] ?? 0),
            'ignored_unavailable_blocks_count' => (int) ($summary['ignored_unavailable_blocks_count'] ?? 0),
            'warnings_count' => count($warnings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function newTimestampedDir(string $prefix): string
    {
        return sprintf('%s/%s_%s', trim($prefix, '/'), now()->format('Ymd_His'), Str::lower(Str::random(4)));
    }
}
