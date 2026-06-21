<?php

namespace App\Console\Commands;

use App\Services\IntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MiodottoreLoginCommand extends Command
{
    protected $signature = 'miodottore:login';

    protected $description = 'Esegue il collegamento automatico a MioDottore in background e salva la sessione operativa';

    public function handle(IntegrationService $integrationService): int
    {
        Log::info('Miodottore login command started.', [
            'mode' => 'background_login',
        ]);

        $this->info('Avvio collegamento automatico MioDottore...');
        try {
            $result = $integrationService->runMiodottoreLoginFlow();
        } catch (\Throwable $exception) {
            Log::error('Miodottore login command crashed.', [
                'error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['success']) {
            $this->info($result['message']);
            $this->line("Sessione salvata in storage/app/{$result['state_path']}");
            $this->line("Artefatti login: storage/app/{$result['output_dir']}");
            Log::info('Miodottore login command completed successfully.', [
                'state_path' => $result['state_path'],
                'output_dir' => $result['output_dir'],
            ]);

            return self::SUCCESS;
        }

        $this->error($result['message']);
        if ($result['output_dir'] !== '') {
            $this->line("Artefatti login: storage/app/{$result['output_dir']}");
        }
        Log::error('Miodottore login command failed.', [
            'message' => $result['message'],
            'output_dir' => $result['output_dir'],
            'state_path' => $result['state_path'],
        ]);

        return self::FAILURE;
    }
}
