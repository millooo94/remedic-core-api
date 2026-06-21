<?php

namespace App\Console\Commands;

use App\Services\IntegrationService;
use Illuminate\Console\Command;

class MiodottoreTestLoginCommand extends Command
{
    protected $signature = 'miodottore:test-login';

    protected $description = 'Verifica la sessione salvata di MioDottore';

    public function handle(IntegrationService $integrationService): int
    {
        $result = $integrationService->verifyMiodottoreAccess();

        $this->info('Avvio verifica accesso MioDottore.');
        $this->line("Stato integrazione: {$result['integration']['status_label']}");
        $this->line($result['message']);

        return $result['status'] === IntegrationService::STATUS_SESSION_VALID ? self::SUCCESS : self::FAILURE;
    }
}
