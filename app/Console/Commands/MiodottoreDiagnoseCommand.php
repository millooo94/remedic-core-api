<?php

namespace App\Console\Commands;

use App\Services\MiodottoreAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class MiodottoreDiagnoseCommand extends Command
{
    protected $signature = 'miodottore:diagnose';

    protected $description = 'Verifica DB, queue e configurazione locale per il collegamento MioDottore';

    public function handle(MiodottoreAccessService $accessService): int
    {
        $checks = [
            'DB OK' => $this->checkDb(),
            'Queue connection configurata' => $this->checkQueueConnection(),
            'Tabella jobs disponibile' => $this->checkJobsTable(),
            'Storage state path scrivibile' => $this->checkStoragePathWritable($accessService),
            'MIODOTTORE_LOGIN_URL presente' => filled($accessService->loginUrl()),
            'MIODOTTORE_VERIFY_URL presente' => filled($accessService->verifyUrl()),
            'Storage state path risolto' => filled($accessService->absoluteStorageStatePath()),
        ];

        foreach ($checks as $label => $ok) {
            $ok ? $this->info("[OK] {$label}") : $this->error("[NO] {$label}");
        }

        $this->newLine();
        $this->line('Queue connection: '.config('queue.default'));
        $this->line('DB connection: '.config('database.default'));
        $this->line('Storage state path: '.$accessService->absoluteStorageStatePath());
        $this->line('PHP binary: '.PHP_BINARY);
        $this->line('php.ini: '.(php_ini_loaded_file() ?: 'non rilevato'));

        return collect($checks)->every(fn (bool $ok) => $ok) ? self::SUCCESS : self::FAILURE;
    }

    private function checkDb(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkQueueConnection(): bool
    {
        return filled(config('queue.default'));
    }

    private function checkJobsTable(): bool
    {
        if (config('queue.default') !== 'database') {
            return true;
        }

        try {
            return Schema::hasTable(config('queue.connections.database.table', 'jobs'));
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkStoragePathWritable(MiodottoreAccessService $accessService): bool
    {
        try {
            $path = $accessService->absoluteStorageStatePath();
            File::ensureDirectoryExists(dirname($path));

            return is_writable(dirname($path));
        } catch (\Throwable) {
            return false;
        }
    }
}
