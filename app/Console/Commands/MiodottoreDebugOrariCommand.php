<?php

namespace App\Console\Commands;

use App\Models\ExternalProviderProfessional;
use App\Models\Professional;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class MiodottoreDebugOrariCommand extends Command
{
    protected $signature = 'miodottore:debug-orari
        {professionalId : ID del professionista collegato a MioDottore}
        {--headless : Forza headless=true}';

    protected $description = 'Debug iniziale Playwright della pagina Orari di MioDottore senza sincronizzazione database';

    public function handle(): int
    {
        $loginUrl = (string) config('services.miodottore.login_url');
        $username = (string) config('services.miodottore.username');
        $password = (string) config('services.miodottore.password');
        $headless = $this->option('headless') || (bool) config('services.miodottore.debug_headless', false);
        $timeoutMs = (int) config('services.miodottore.debug_timeout_ms', 90000);
        $slowMoMs = (int) config('services.miodottore.debug_slowmo_ms', 150);
        $chromiumPath = (string) (config('services.miodottore.debug_chromium_path') ?? '');

        if ($loginUrl === '' || $username === '' || $password === '') {
            $this->error('Configurazione MioDottore incompleta. Imposta MIODOTTORE_LOGIN_URL, MIODOTTORE_USERNAME e MIODOTTORE_PASSWORD nel file .env.');

            return self::FAILURE;
        }

        $professionalId = (int) $this->argument('professionalId');
        $professional = Professional::query()->find($professionalId);
        if (! $professional) {
            $this->error('Professionista non trovato.');

            return self::FAILURE;
        }

        $providerProfile = $this->resolveProviderProfile($professional);
        if ($providerProfile === null || ! is_string($providerProfile->external_url) || trim($providerProfile->external_url) === '') {
            $this->error('URL MioDottore del professionista non configurato.');

            return self::FAILURE;
        }
        $targetUrl = trim($providerProfile->external_url);

        $runId = now()->format('Ymd_His').($professionalId ? "_professional_{$professionalId}" : '_manual');
        $relativeOutputDir = "miodottore-debug/{$runId}";
        $absoluteOutputDir = Storage::disk('local')->path($relativeOutputDir);
        File::ensureDirectoryExists($absoluteOutputDir);

        $scriptPath = base_path('scripts/miodottore/debug-orari.mjs');
        $args = [
            'node',
            $scriptPath,
            '--login-url', $loginUrl,
            '--username', $username,
            '--password', $password,
            '--target-url', $targetUrl,
            '--output-dir', $absoluteOutputDir,
            '--headless', $headless ? 'true' : 'false',
            '--timeout-ms', (string) $timeoutMs,
            '--slowmo-ms', (string) $slowMoMs,
        ];

        if ($chromiumPath !== '') {
            $args[] = '--chromium-path';
            $args[] = $chromiumPath;
        }

        $this->info('Avvio debug Playwright MioDottore...');
        $this->line("Output debug: storage/app/{$relativeOutputDir}");
        $this->line("URL Orari target: {$targetUrl}");
        $this->line('Modalita browser: '.($headless ? 'headless' : 'visibile'));

        $process = new Process($args, base_path(), null, null, 180);
        $process->setTimeout(180);
        $process->setIdleTimeout(180);

        $exitCode = $process->run(function (string $type, string $buffer): void {
            $output = trim($buffer);
            if ($output === '') {
                return;
            }

            if ($type === Process::ERR) {
                $this->newLine();
                $this->components->error($output);
                return;
            }

            $this->line($output);
        });

        $this->newLine();
        $this->line('File attesi nel debug output:');
        $this->line('- login-success.png');
        $this->line('- orari-page.png');
        $this->line('- trace.zip');
        $this->line('- orari-page.html');
        $this->line('- orari-page.txt');
        $this->line('- error.png in caso di errore');

        if ($exitCode !== 0) {
            $this->error("Debug MioDottore fallito. Controlla storage/app/{$relativeOutputDir}.");

            return self::FAILURE;
        }

        $this->info("Debug MioDottore completato. Controlla storage/app/{$relativeOutputDir}.");

        return self::SUCCESS;
    }

    private function resolveProviderProfile(Professional $professional): ?ExternalProviderProfessional
    {
        return ExternalProviderProfessional::query()
            ->where('professional_id', $professional->id)
            ->where('provider', 'miodottore')
            ->first();
    }
}
