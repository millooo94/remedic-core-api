<?php

namespace App\Console\Commands;

use App\Services\OldCoreDataImportService;
use Illuminate\Console\Command;
use Throwable;

class RebuildFromOldCoreCommand extends Command
{
    protected $signature = 'old-core:rebuild-from-source
        {--dry-run : Simula svuotamento e ricostruzione senza scrivere nulla}
        {--force : Esegue davvero lo svuotamento delle tabelle applicative e il rebuild dai dati old_core}';

    protected $description = 'Svuota i dati applicativi del nuovo DB e li ricostruisce esclusivamente dalla sorgente old_core, preservando i duplicati della sorgente.';

    public function handle(OldCoreDataImportService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force) {
            $this->error('Per eseguire il rebuild reale devi usare --force. Usa --dry-run per la simulazione.');

            return self::FAILURE;
        }

        if (! $service->sourceConnectionConfigured()) {
            $this->error('Configura OLD_DB_DATABASE e i parametri OLD_DB_* prima di usare old-core:rebuild-from-source.');

            return self::FAILURE;
        }

        try {
            $report = $service->rebuildFromSource($dryRun);
        } catch (Throwable $throwable) {
            $this->error('Rebuild old_core non eseguito: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun
            ? 'Dry-run rebuild old_core completato.'
            : 'Rebuild old_core completato.');

        $this->newLine();
        $this->line('Analisi rebuild');
        $this->table(
            ['Voce', 'Valore'],
            [
                ['Dump SQL', (string) ($report['analysis']['source_dump_path'] ?? 'n/d')],
                ['Connessione sorgente', (string) ($report['analysis']['source_connection'] ?? 'n/d')],
                ['Connessione disponibile', ($report['source_connection_available'] ?? false) ? 'si' : 'no'],
                ['Modalita', (string) ($report['analysis']['mode'] ?? 'rebuild_from_source')],
                ['Preserva duplicati sorgente', ($report['preserve_duplicates'] ?? false) ? 'si' : 'no'],
            ],
        );

        $clearPlan = [];
        foreach (($report['clear_plan'] ?? []) as $table => $count) {
            $clearPlan[] = [$table, (string) $count];
        }

        if ($clearPlan !== []) {
            $this->newLine();
            $this->line('Tabelle che verrebbero svuotate');
            $this->table(['Tabella', 'Righe attuali'], $clearPlan);
        }

        foreach (($report['analysis']['assumptions'] ?? []) as $assumption) {
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
                ['Righe da eliminare nel nuovo DB', (string) ($report['totals']['rows_to_delete'] ?? 0)],
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
