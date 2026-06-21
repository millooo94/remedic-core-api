<?php

namespace App\Console\Commands;

use App\Services\IntegrationService;
use Illuminate\Console\Command;

class MiodottoreSyncPatientsCommand extends Command
{
    protected $signature = 'miodottore:sync-patients';

    protected $description = 'Placeholder base per la futura sincronizzazione globale dei pazienti da MioDottore';

    public function handle(IntegrationService $integrationService): int
    {
        $result = $integrationService->runSyncPlaceholder('sync_patients');

        $this->info('Avvio placeholder sincronizzazione pazienti da MioDottore.');
        $this->line("Stato integrazione: {$result['integration']['status_label']}");
        $this->line($result['message']);
        $this->newLine();
        $this->comment('TODO futuri:');
        $this->line('1. leggere rubrica e anagrafiche da MioDottore');
        $this->line('2. mappare i pazienti nel dominio Remedic Core');
        $this->line('3. gestire deduplica e aggiornamenti incrementali');

        return self::SUCCESS;
    }
}
