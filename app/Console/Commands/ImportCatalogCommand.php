<?php

namespace App\Console\Commands;

use App\Services\CatalogImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ImportCatalogCommand extends Command
{
    protected $signature = 'catalog:import
        {path? : Percorso del file sorgente PHP o JSON}
        {--label=manual_import : Etichetta sorgente per alias e tracciamento}';

    protected $description = 'Importa e normalizza il catalogo prestazioni, mostrando un report finale.';

    public function handle(CatalogImportService $catalogImportService): int
    {
        $path = (string) ($this->argument('path') ?: database_path('data/service_catalog_dump.php'));

        if (! is_file($path)) {
            $this->error("File sorgente non trovato: {$path}");

            return self::FAILURE;
        }

        $rows = $this->loadRows($path);

        if (! is_array($rows)) {
            $this->error('Il file sorgente deve restituire un array PHP oppure un JSON valido.');

            return self::FAILURE;
        }

        $report = $catalogImportService->import($rows, (string) $this->option('label'));

        $this->table(
            ['Voce', 'Valore'],
            [
                ['Record sorgente', (string) Arr::get($report, 'source_records', 0)],
                ['Servizi canonici creati', (string) Arr::get($report, 'services_created', 0)],
                ['Alias creati', (string) Arr::get($report, 'aliases_created', 0)],
                ['Collegamenti professionista-prestazione', (string) Arr::get($report, 'professional_services_created', 0)],
                ['Record senza prezzo', (string) Arr::get($report, 'records_without_price', 0)],
                ['Record dubbi/non riconciliati', (string) count(Arr::get($report, 'unresolved_records', []))],
            ],
        );

        if (! empty($report['unresolved_records'])) {
            $this->newLine();
            $this->warn('Record dubbi/non riconciliati:');

            foreach ($report['unresolved_records'] as $row) {
                $reason = Arr::get($row, 'reason', 'Motivo non disponibile');
                $index = Arr::get($row, 'index', '?');
                $professional = Arr::get($row, 'row.professional', 'Non specificato');
                $service = Arr::get($row, 'row.service_name', 'Non specificato');

                $this->line("- #{$index} | {$professional} | {$service} | {$reason}");
            }
        }

        $this->info('Import catalogo completato.');

        return self::SUCCESS;
    }

    private function loadRows(string $path): mixed
    {
        if (str_ends_with(strtolower($path), '.json')) {
            return json_decode((string) file_get_contents($path), true);
        }

        return require $path;
    }
}
