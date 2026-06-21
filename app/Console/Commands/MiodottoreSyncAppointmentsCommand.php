<?php

namespace App\Console\Commands;

use App\Services\IntegrationService;
use Illuminate\Console\Command;

class MiodottoreSyncAppointmentsCommand extends Command
{
    protected $signature = 'miodottore:sync-appointments';

    protected $description = 'Placeholder base per la futura sincronizzazione globale degli appuntamenti da MioDottore';

    public function handle(IntegrationService $integrationService): int
    {
        $result = $integrationService->runSyncPlaceholder('sync_appointments');

        $this->info('Avvio placeholder sincronizzazione appuntamenti da MioDottore.');
        $this->line("Stato integrazione: {$result['integration']['status_label']}");
        $this->line($result['message']);
        $this->newLine();
        $this->comment('TODO futuri:');
        $this->line('1. leggere agenda e pazienti da MioDottore');
        $this->line('2. creare o aggiornare gli appointments del Core');
        $this->line('3. sincronizzare stato, orari e riferimenti professionista/paziente');

        return self::SUCCESS;
    }
}
