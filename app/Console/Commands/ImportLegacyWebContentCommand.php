<?php

namespace App\Console\Commands;

use App\Services\LegacyWebContentImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacyWebContentCommand extends Command
{
    protected $signature = 'merge:import-web-content
        {--only=* : Gruppi da importare: pages, blog_posts, site_settings, redirects, sections, faq_items}
        {--write : Esegue davvero l\'upsert invece della simulazione}';

    protected $description = 'Importa nel Core i contenuti Web del backend legacy con supporto dry-run e upsert idempotente.';

    public function handle(LegacyWebContentImportService $service): int
    {
        if (blank(config('database.connections.legacy_backend.database'))) {
            $this->error('Configura LEGACY_BACKEND_DB_DATABASE prima di usare merge:import-web-content.');

            return self::FAILURE;
        }

        $groups = (array) $this->option('only');
        $dryRun = ! (bool) $this->option('write');

        try {
            $report = $service->import($groups, $dryRun);
        } catch (Throwable $throwable) {
            $this->error('Import legacy non eseguito: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulazione import contenuti Web completata.'
            : 'Import contenuti Web completato.');

        foreach ($report['items'] as $group => $item) {
            $this->newLine();
            $this->line(strtoupper($group));
            $this->table(
                ['Voce', 'Valore'],
                [
                    ['Record sorgente', (string) ($item['source_records'] ?? 0)],
                    ['Creati', (string) ($item['created'] ?? 0)],
                    ['Aggiornati', (string) ($item['updated'] ?? 0)],
                    ['Saltati', (string) ($item['skipped'] ?? 0)],
                ],
            );

            foreach (($item['warnings'] ?? []) as $warning) {
                $this->warn($warning);
            }
        }

        return self::SUCCESS;
    }
}
