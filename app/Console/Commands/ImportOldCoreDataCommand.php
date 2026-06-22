<?php

namespace App\Console\Commands;

use App\Services\OldCoreDataImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportOldCoreDataCommand extends Command
{
    protected $signature = 'merge:import-core-data
        {--only=* : Gruppi da importare: specializations, professionals, patients, services, performance-records, expenses, marketing, settings}
        {--dry-run : Esegue solo analisi e simulazione senza scritture}
        {--force : Esegue davvero il merge sul database attuale}';

    protected $description = 'Analizza il dump del vecchio Core e importa in modo idempotente i dati nella nuova struttura.';

    public function handle(OldCoreDataImportService $service): int
    {
        $groups = $service->normalizeGroups((array) $this->option('only'));
        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('force');

        if (! $dryRun && ! $service->sourceConnectionConfigured()) {
            $this->error('Configura OLD_DB_DATABASE e gli altri parametri OLD_DB_* prima di eseguire il merge reale.');

            return self::FAILURE;
        }

        try {
            $report = $service->import($groups, $dryRun);
        } catch (Throwable $throwable) {
            $this->error('Merge dati non eseguito: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun
            ? 'Dry-run merge dati old_core completato.'
            : 'Merge dati old_core completato.');

        $analysis = $report['analysis'];
        $this->newLine();
        $this->line('Analisi sorgente');
        $this->table(
            ['Voce', 'Valore'],
            [
                ['Dump SQL', (string) ($analysis['source_dump_path'] ?? 'n/d')],
                ['Connessione sorgente', (string) ($analysis['source_connection'] ?? 'n/d')],
                ['Connessione disponibile', ($report['source_connection_available'] ?? false) ? 'si' : 'no'],
                ['Tabelle riconosciute', (string) count($analysis['recognized_source_tables'] ?? [])],
                ['Gruppi selezionati', implode(', ', $report['groups'] ?? [])],
            ],
        );

        foreach (($analysis['assumptions'] ?? []) as $assumption) {
            $this->comment('- '.$assumption);
        }

        foreach ($report['items'] as $group => $item) {
            $this->newLine();
            $this->line(strtoupper($group));
            $this->table(
                ['Voce', 'Valore'],
                [
                    ['Tabelle sorgente', implode(', ', $item['source_tables'] ?? [])],
                    ['Tabelle target', implode(', ', $item['target_tables'] ?? [])],
                    ['Righe sorgente', (string) ($item['source_records'] ?? 0)],
                    ['Create', (string) ($item['created'] ?? 0)],
                    ['Aggiornate', (string) ($item['updated'] ?? 0)],
                    ['Saltate', (string) ($item['skipped'] ?? 0)],
                    ['Warning', (string) count($item['warnings'] ?? [])],
                    ['Non mappabili', (string) count($item['unmappable'] ?? [])],
                    ['Errori', (string) count($item['errors'] ?? [])],
                ],
            );

            foreach (($item['warnings'] ?? []) as $warning) {
                $this->warn($warning);
            }

            foreach (($item['unmappable'] ?? []) as $unmappable) {
                $this->warn('Non mappabile: '.$unmappable);
            }

            foreach (($item['errors'] ?? []) as $error) {
                $this->error($error);
            }
        }

        $this->newLine();
        $this->line('Totali');
        $this->table(
            ['Voce', 'Valore'],
            [
                ['Righe sorgente', (string) ($report['totals']['source_records'] ?? 0)],
                ['Create', (string) ($report['totals']['created'] ?? 0)],
                ['Aggiornate', (string) ($report['totals']['updated'] ?? 0)],
                ['Saltate', (string) ($report['totals']['skipped'] ?? 0)],
                ['Warning', (string) ($report['totals']['warnings'] ?? 0)],
                ['Non mappabili', (string) ($report['totals']['unmappable'] ?? 0)],
                ['Errori', (string) ($report['totals']['errors'] ?? 0)],
            ],
        );

        return ($report['totals']['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
