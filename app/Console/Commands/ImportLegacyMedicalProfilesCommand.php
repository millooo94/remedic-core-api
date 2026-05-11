<?php

namespace App\Console\Commands;

use App\Services\LegacyMedicalProfileImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportLegacyMedicalProfilesCommand extends Command
{
    protected $signature = 'merge:import-medical-profiles
        {--only=* : Gruppi da importare: specializations, professional_public_profiles, service_specializations, professional_specializations, professional_services, professional_degrees, professional_academic_specializations, professional_board_registrations}
        {--write : Esegue davvero l\'upsert invece della simulazione}';

    protected $description = 'Importa nel Core tassonomie e profili medici del backend legacy con supporto dry-run e upsert idempotente.';

    public function handle(LegacyMedicalProfileImportService $service): int
    {
        if (blank(config('database.connections.legacy_backend.database'))) {
            $this->error('Configura LEGACY_BACKEND_DB_DATABASE prima di usare merge:import-medical-profiles.');

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
            ? 'Simulazione import profili medici completata.'
            : 'Import profili medici completato.');

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
